<?php

/*
 * The regression net: what the app hands to its pages and API, as the snapshot
 * account (USER_ID) sees it, frozen so two runs can be compared field by field.
 *
 *   php scripts/snapshot.php base          copy today's database and provider cache as the frozen base
 *   php scripts/snapshot.php take <label>  render every page from a fresh copy of the base
 *   php scripts/snapshot.php diff <a> <b>  what changed between two takes
 *   php scripts/snapshot.php restore       the snapshot account's backup imported into an empty account: time, queries, rows
 *   php scripts/snapshot.php profile <label> [--frozen-clock] [--live] [--only=<page>,<page>]
 *                                          every page cold and warm: time, queries, response size, the costliest queries and their plans
 *
 * A take migrates a throwaway copy of the base with the code as it is now,
 * freezes the clock at the moment the base was made, and cuts the network
 * (providers see a dead connection and degrade the way they do in real life),
 * so the only thing that differs between two takes is the code.
 */

use App\Integrations\Import\Data\ImportInput;
use App\Integrations\Import\Data\ImportSelection;
use App\Integrations\VideTrack\Backup\BackupWriter;
use App\Jobs\RunImport;
use App\Models\ListItem;
use App\Models\MediaItem;
use App\Models\TaskRun;
use App\Models\User;
use App\Models\WatchCycle;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiter;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Application;
use Illuminate\Http\Client\Events\ResponseReceived;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tighten\Ziggy\BladeRouteGenerator;

const ROOT = __DIR__.'/..';
const SNAPSHOTS = ROOT.'/storage/snapshots';
const BASE = SNAPSHOTS.'/base';
const USER_ID = 1;

/** Keys that change on every request and say nothing about the page. */
const VOLATILE = ['errors', 'flash', 'ziggy', 'csrf_token', 'version', 'encryptHistory', 'clearHistory'];

/** Warm visits per page in a profile; the median is the warm time. */
const WARM_VISITS = 3;

/** Queries a profile keeps per page, costliest first. */
const COSTLIEST_QUERIES = 5;

/** Props a profile keeps per page, largest first. */
const LARGEST_PROPS = 5;

/** How much of a statement a profile keeps: enough to recognise it. */
const SQL_PREVIEW = 600;

/** zlib's default level: an estimate of the compressed size, whichever level the web server picks. */
const GZIP_LEVEL = 6;

require ROOT.'/vendor/autoload.php';

$command = $argv[1] ?? '';

match ($command) {
    'base' => makeBase(),
    'take' => take($argv[2] ?? exit("Usage: php scripts/snapshot.php take <label>\n")),
    'diff' => diff($argv[2] ?? '', $argv[3] ?? exit("Usage: php scripts/snapshot.php diff <a> <b>\n")),
    'restore' => restore(),
    'profile' => profile(
        $argv[2] ?? exit("Usage: php scripts/snapshot.php profile <label> [--frozen-clock] [--live] [--only=<page>,<page>]\n"),
        frozenClock: in_array('--frozen-clock', $argv, true),
        live: in_array('--live', $argv, true),
        only: onlyPages($argv),
    ),
    default => exit("Usage: php scripts/snapshot.php base | take <label> | diff <a> <b> | restore | profile <label>\n"),
};

function makeBase(): void
{
    if (is_dir(BASE)) {
        exit('The base exists ('.BASE."). Remove it first to make a new one — every take compares against it.\n");
    }

    mkdir(BASE.'/cache', 0777, true);
    passthru('sqlite3 '.escapeshellarg(ROOT.'/database/database.sqlite').' '.escapeshellarg('.backup '.BASE.'/database.sqlite'), $status);
    $status === 0 || exit("Copying the database failed.\n");
    passthru('cp -r '.escapeshellarg(ROOT.'/storage/framework/cache/data').'/. '.escapeshellarg(BASE.'/cache'));

    // One live pass, so every page a take visits finds its provider data in
    // the cache: a take runs offline, and a game or a book page without
    // cached data has nothing to fall back to. Ordinary requests with the
    // instance's keys, the same ones a visit in the browser makes.
    $app = boot(BASE);
    Queue::fake();
    Mail::fake();
    visitPages($app, function (string $name, array $page): void {
        printf("warmed %-33s %4d\n", $name, $page['status']);
    });

    file_put_contents(BASE.'/meta.json', json_encode(['frozenAt' => CarbonImmutable::now('UTC')->toIso8601String()], JSON_PRETTY_PRINT)."\n");

    echo 'Base made at '.BASE."\n";
}

function take(string $label): void
{
    is_file(BASE.'/meta.json') || exit("No base yet: php scripts/snapshot.php base\n");

    $out = SNAPSHOTS."/{$label}";
    is_dir($out) && exit("The take {$label} exists.\n");
    mkdir($out, 0777, true);

    $work = workCopy();
    $app = boot($work);
    freezeClock();
    offline();

    $timings = [];

    visitPages($app, function (string $name, array $page, array $timing) use ($out, &$timings): void {
        $timings[$name] = $timing;
        file_put_contents("{$out}/{$name}.json", json_encode(normalize($page), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n");
        printf("%-40s %4d  %5d ms  %4d queries\n", $name, $page['status'], $timing['ms'], $timing['queries']);
    });

    file_put_contents("{$out}/_timings.json", json_encode($timings, JSON_PRETTY_PRINT)."\n");
    passthru('rm -rf '.escapeshellarg($work));

    echo "Take written to {$out}\n";
}

/**
 * The write path's yardstick: the snapshot account's backup, taken with the code as it
 * is, imported into an empty account of a throwaway copy of the base — how
 * long it takes, how many queries it costs, and whether every kind of row
 * came back as many times as it left.
 */
function restore(): void
{
    is_file(BASE.'/meta.json') || exit("No base yet: php scripts/snapshot.php base\n");

    $work = workCopy();
    boot($work);
    freezeClock();
    offline();

    $owner = User::query()->findOrFail(USER_ID);
    $file = "{$work}/backup.json";
    $out = fopen($file, 'w') ?: exit("Cannot write {$file}.\n");
    app(BackupWriter::class)->write($owner, $out);
    fclose($out);

    $target = User::factory()->create();
    $run = TaskRun::queueTask('import-videtrack', $target->id);

    // Counted rather than logged: a whole library's import would not fit in the query log.
    $queries = 0;
    $databaseMs = 0.0;
    DB::listen(function (QueryExecuted $query) use (&$queries, &$databaseMs): void {
        $queries++;
        $databaseMs += $query->time;
    });
    $started = hrtime(true);
    app()->call([new RunImport($run, 'videtrack', ImportInput::file($file), ImportSelection::everything()), 'handle']);
    $ms = (int) round((hrtime(true) - $started) / 1e6);

    $run->refresh();
    printf("import: %s in %d ms, %d queries taking %d ms\n%s\n", $run->status->value, $ms, $queries, $databaseMs, $run->summary);

    foreach (['plays', 'ratings', 'favorites', 'mediaStates', 'follows'] as $relation) {
        printf("%-12s %6d -> %6d\n", $relation, $owner->{$relation}()->count(), $target->{$relation}()->count());
    }

    printf("%-12s %6d -> %6d\n", 'listItems', ListItem::query()->whereIn('list_id', $owner->lists()->pluck('id'))->count(), ListItem::query()->whereIn('list_id', $target->lists()->pluck('id'))->count());
    printf("%-12s %6d -> %6d\n", 'cycles', WatchCycle::query()->where('user_id', $owner->id)->count(), WatchCycle::query()->where('user_id', $target->id)->count());

    passthru('rm -rf '.escapeshellarg($work));
}

/**
 * Every page cold (its first visit in the process: classes, translations and
 * the app's own caches still to load) and warm (the median of WARM_VISITS
 * visits after it), with its size and the queries that cost the most.
 *
 * The clock runs live by default: a frozen Carbon makes every date the app
 * creates noticeably slower, and a page with many dates would look several
 * times costlier than it is. --frozen-clock measures what a take sees.
 *
 * --live starts from an empty provider cache with the network on: what a
 * page costs the first time anyone opens it, providers asked with the
 * instance's keys. Narrow it with --only, so a run asks the services for a
 * few pages, not for every title the account has.
 *
 * @param  list<string>|null  $only  page names; null for every page
 */
function profile(string $label, bool $frozenClock, bool $live, ?array $only): void
{
    is_file(BASE.'/meta.json') || exit("No base yet: php scripts/snapshot.php base\n");

    $out = SNAPSHOTS."/_profiles/{$label}.json";
    is_file($out) && exit("The profile {$label} exists.\n");
    $only === [] && exit("--only names no page.\n");
    is_dir(dirname($out)) || mkdir(dirname($out), 0777, true);

    $work = workCopy(providerCache: ! $live);
    $app = boot($work);
    $frozenClock ? freezeClock() : unfreezeClock();
    // Live, the providers are asked for real; nothing is queued or sent all the same.
    $live ? sendNothing() : offline();

    // The page names are known only on the booted copy (titles come from the
    // library), so a wrong one removes the copy before it stops.
    $known = array_keys(pages(User::query()->findOrFail(USER_ID)));
    $unknown = array_diff($only ?? [], $known);

    if ($unknown !== []) {
        passthru('rm -rf '.escapeshellarg($work));
        exit('No page named '.implode(', ', $unknown).'; the pages are: '.implode(', ', $known)."\n");
    }

    $pragmas = [];

    foreach (['journal_mode', 'synchronous', 'busy_timeout', 'foreign_keys', 'cache_size', 'temp_store', 'mmap_size'] as $pragma) {
        $row = (array) (DB::select("PRAGMA {$pragma}")[0] ?? []);
        $pragmas[$pragma] = reset($row);
    }

    echo 'PRAGMA '.implode(', ', array_map(fn (string $key, mixed $value): string => "{$key}={$value}", array_keys($pragmas), $pragmas))."\n\n";

    // What a page asked the providers for, by host: a cold page's cost is its requests.
    $requests = [];
    Event::listen(ResponseReceived::class, function (ResponseReceived $event) use (&$requests): void {
        $host = (string) parse_url((string) $event->request->url(), PHP_URL_HOST);
        $requests[$host] ??= ['count' => 0, 'ms' => 0];
        $requests[$host]['count']++;
        $requests[$host]['ms'] += (int) round(((float) ($event->response->handlerStats()['total_time'] ?? 0)) * 1000);
    });

    $pages = [];

    for ($visit = 0; $visit <= WARM_VISITS; $visit++) {
        visitPages($app, only: $only, onPage: function (string $name, array $page, array $timing, array $log) use ($visit, &$pages, &$requests): void {
            $asked = $requests;
            $requests = [];

            if ($visit > 0) {
                $pages[$name]['warmVisits'][] = $timing['ms'];

                return;
            }

            $props = is_array($page['props'] ?? null) ? $page['props'] : [];
            $propBytes = array_map(fn (mixed $value): int => strlen((string) json_encode($value)), $props);
            arsort($propBytes);

            $pages[$name] = [
                'status' => $page['status'],
                'coldMs' => $timing['ms'],
                'queries' => $timing['queries'],
                'databaseMs' => round(array_sum(array_column($log, 'time')), 1),
                'bytes' => $timing['bytes'],
                'gzipBytes' => $timing['gzipBytes'],
                'props' => array_slice($propBytes, 0, LARGEST_PROPS, true),
                'costliest' => costliestQueries($log),
                'requests' => $asked,
                'warmVisits' => [],
            ];
        });
    }

    printf("%-28s %4s %7s %7s %5s %8s %8s %8s\n", 'page', 'code', 'cold', 'warm', 'sql', 'sql ms', 'KB', 'KB gzip');

    foreach ($pages as $name => &$page) {
        $warm = $page['warmVisits'];
        sort($warm);
        $page['warmMs'] = $warm[intdiv(count($warm), 2)];
        printf("%-28s %4d %5d ms %5d ms %5d %8.1f %8.1f %8.1f\n", $name, $page['status'], $page['coldMs'], $page['warmMs'], $page['queries'], $page['databaseMs'], $page['bytes'] / 1024, $page['gzipBytes'] / 1024);

        foreach ($page['requests'] as $host => $asked) {
            printf("%-28s %d requests to %s, %d ms\n", '', $asked['count'], $host, $asked['ms']);
        }
    }

    unset($page);

    $profile = [
        'commit' => trim((string) shell_exec('git -C '.escapeshellarg(ROOT).' rev-parse --short HEAD')),
        'base' => frozenAt(),
        'clock' => $frozenClock ? 'frozen' : 'live',
        'network' => $live ? 'live, empty provider cache' : 'off',
        'php' => PHP_VERSION,
        'opcache' => (bool) ini_get('opcache.enable_cli'),
        'library' => librarySize(),
        'pragmas' => $pragmas,
        'pages' => $pages,
    ];

    file_put_contents($out, json_encode($profile, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n");
    passthru('rm -rf '.escapeshellarg($work));

    echo "\nProfile written to {$out}\n";
}

/**
 * The same statement run many times counts as one, with its count: an N+1
 * is a cheap query that adds up.
 *
 * @param  list<array{query: string, bindings: array<int, mixed>, time: float|null}>  $log
 * @return list<array{sql: string, count: int, ms: float, plan: list<string>}>
 */
function costliestQueries(array $log): array
{
    $grouped = [];

    foreach ($log as $entry) {
        $sql = $entry['query'];
        $grouped[$sql] ??= ['sql' => $sql, 'count' => 0, 'ms' => 0.0, 'bindings' => $entry['bindings']];
        $grouped[$sql]['count']++;
        $grouped[$sql]['ms'] += (float) $entry['time'];
    }

    usort($grouped, fn (array $a, array $b): int => $b['ms'] <=> $a['ms']);

    return array_map(function (array $query): array {
        $plan = [];

        if (preg_match('/^\s*(select|with)\b/i', $query['sql']) === 1) {
            foreach (DB::select('EXPLAIN QUERY PLAN '.$query['sql'], $query['bindings']) as $row) {
                $plan[] = (string) ((array) $row)['detail'];
            }
        }

        return ['sql' => mb_substr($query['sql'], 0, SQL_PREVIEW), 'count' => $query['count'], 'ms' => round($query['ms'], 1), 'plan' => $plan];
    }, array_slice($grouped, 0, COSTLIEST_QUERIES));
}

/** @return array<string, int> what the snapshot account's library holds, for the report */
function librarySize(): array
{
    return [
        'plays' => DB::table('plays')->where('user_id', USER_ID)->count(),
        'libraryEntries' => DB::table('library_entries')->where('user_id', USER_ID)->count(),
        'lists' => DB::table('lists')->where('user_id', USER_ID)->count(),
        'follows' => DB::table('follows')->where('user_id', USER_ID)->count(),
        'notifications' => DB::table('notifications')->where('user_id', USER_ID)->count(),
        'mediaItems' => DB::table('media_items')->count(),
    ];
}

/** A throwaway copy of the base, so a run never changes the base or the live data. */
function workCopy(bool $providerCache = true): string
{
    $work = sys_get_temp_dir().'/snapshot-'.uniqid();
    mkdir("{$work}/cache", 0777, true);
    copy(BASE.'/database.sqlite', "{$work}/database.sqlite");

    if ($providerCache) {
        passthru('cp -r '.escapeshellarg(BASE.'/cache').'/. '.escapeshellarg("{$work}/cache"));
    }

    return $work;
}

/**
 * @param  list<string>  $argv
 * @return list<string>|null
 */
function onlyPages(array $argv): ?array
{
    foreach ($argv as $argument) {
        if (str_starts_with($argument, '--only=')) {
            return array_values(array_filter(explode(',', substr($argument, strlen('--only=')))));
        }
    }

    return null;
}

/** The clock at the moment the base was made, so what has aired is what it was then. */
function freezeClock(): void
{
    CarbonImmutable::setTestNow(frozenAt());
    Carbon::setTestNow(frozenAt());
}

function frozenAt(): string
{
    return json_decode((string) file_get_contents(BASE.'/meta.json'), true)['frozenAt'];
}

function unfreezeClock(): void
{
    CarbonImmutable::setTestNow();
    Carbon::setTestNow();
}

/** Providers see a dead connection and degrade the way they do in real life; nothing is queued or sent. */
function offline(): void
{
    Http::preventStrayRequests();
    Http::fake(['*' => Http::failedConnection()]);
    Sleep::fake();
    sendNothing();
}

function sendNothing(): void
{
    Queue::fake();
    Mail::fake();
}

/** The app on the database and provider cache in $dir, migrated with the code as it is now. */
function boot(string $dir): Application
{
    foreach (['DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => "{$dir}/database.sqlite", 'CACHE_STORE' => 'file', 'QUEUE_CONNECTION' => 'sync', 'MAIL_MAILER' => 'array'] as $key => $value) {
        putenv("{$key}={$value}");
        $_ENV[$key] = $_SERVER[$key] = $value;
    }

    $app = require ROOT.'/bootstrap/app.php';
    $app->make(ConsoleKernel::class)->bootstrap();

    // A cached config ignores the environment above: every migration and
    // page visit would then land in the live database.
    if (config('database.connections.sqlite.database') !== "{$dir}/database.sqlite") {
        fwrite(STDERR, "The app is not on the copy (a cached config?): run `php artisan config:clear` first.\n");
        exit(1);
    }

    // The store was built during bootstrap, on the live cache: point it at the
    // copy and build it again, or a take reads (and writes) the live cache.
    config(['cache.stores.file.path' => "{$dir}/cache", 'cache.stores.file.lock_path' => "{$dir}/cache"]);
    $app->make('cache')->forgetDriver(['file']);
    $app->forgetInstance('cache.store');
    Cache::clearResolvedInstances();

    // The limiter keeps the limits the app defined at boot; only its counters
    // move to the copy, so a take never spends the live instance's budget.
    $limiter = $app->make(RateLimiter::class);
    $store = $app->make('cache')->store('file');
    (fn () => $this->cache = $store)->call($limiter);

    $directory = Cache::store('file')->getStore()->getDirectory();
    $directory === "{$dir}/cache" || exit("The snapshot would use the cache at {$directory}, not its copy.\n");

    // Frozen before the migrations: one that works something out from the
    // date (what has aired) has to see the day the pages are rendered on.
    // Making the base itself runs live.
    is_file(BASE.'/meta.json') && freezeClock();

    $app->make(ConsoleKernel::class)->call('migrate', ['--force' => true]);
    // As the container's boot does after migrating.
    $app->make(ConsoleKernel::class)->call('vide:library:rebuild');
    $app->make(ConsoleKernel::class)->call('vide:database:optimize');

    return $app;
}

/**
 * Every page, in the same order each time (a page can leave a trace the next
 * one shows, like "recently viewed"), handed to $onPage with its timing.
 *
 * The size is every response the page took, its deferred props included:
 * as sent, and an estimate of it compressed.
 *
 * @param  callable(string, array<string, mixed>, array{ms: int, queries: int, bytes: int, gzipBytes: int}, list<array{query: string, bindings: array<int, mixed>, time: float|null}>): void  $onPage
 * @param  list<string>|null  $only  page names; null for every page
 */
function visitPages(Application $app, callable $onPage, ?array $only = null): void
{
    $user = User::query()->findOrFail(USER_ID);
    $token = $user->createToken('snapshot');
    $kernel = $app->make(HttpKernel::class);
    $pages = pages($user);

    foreach ($only === null ? $pages : array_intersect_key($pages, array_flip($only)) as $name => $url) {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $started = hrtime(true);

        $page = str_starts_with($url, '/api/') ? apiPage($kernel, $url, $token->plainTextToken) : inertiaPage($kernel, $user, $url);

        $ms = (int) round((hrtime(true) - $started) / 1e6);
        $log = DB::getQueryLog();
        DB::disableQueryLog();
        // Measured after the clock stopped: compressing is the script's work, not the page's.
        $bytes = array_sum(array_map('strlen', $page['bodies']));
        $gzipBytes = array_sum(array_map(fn (string $body): int => strlen((string) gzencode($body, GZIP_LEVEL)), $page['bodies']));
        unset($page['bodies']);

        $onPage($name, $page, ['ms' => $ms, 'queries' => count($log), 'bytes' => $bytes, 'gzipBytes' => $gzipBytes], $log);
    }

    $token->accessToken->delete();
}

/**
 * Every page worth guarding, named. Titles and creators are picked from
 * the snapshot account's library in a fixed order, so every take visits the same ones.
 *
 * @return array<string, string> name => URL
 */
function pages(User $user): array
{
    $username = $user->username;
    $pages = [
        'dashboard' => '/dashboard',
        'calendar' => '/calendar',
        'calendar-week' => '/calendar?view=week',
        'countdowns' => '/countdowns',
        'statistics' => '/statistics?period=2025',
        'statistics-movie' => '/statistics?period=2025&type=movie',
        'statistics-show' => '/statistics?period=2025&type=show',
        'statistics-all' => '/statistics?period=all',
        'statistics-range' => '/statistics?from=2024-03-12&to=2025-01-05',
        'suggestions' => '/suggestions',
        'suggestions-released' => '/suggestions/released-since-added',
        'following' => '/following',
        'notifications' => '/notifications',
        'notifications-feed' => '/notifications/feed',
        'lists' => '/lists',
        'discover' => '/discover',
        'search' => '/search',
        'archived' => '/archived',
        'about' => '/about',
        'profile' => "/user/{$username}",
        'profile-history' => "/user/{$username}/history",
        'profile-ratings' => "/user/{$username}/ratings",
        'profile-favorites' => "/user/{$username}/favorites",
        'profile-progress' => "/user/{$username}/progress",
        'profile-lists' => "/user/{$username}/lists",
        'settings-profile' => '/settings/profile',
        'settings-preferences' => '/settings/preferences',
        'settings-tracking' => '/settings/tracking',
        'settings-integrations' => '/settings/integrations',
        'settings-import' => '/settings/import',
        'settings-export' => '/settings/export',
        'settings-api' => '/settings/api',
        'settings-tasks' => '/settings/tasks',
        'export-json' => '/settings/export/videtrack',
        'api-me' => '/api/v1/me',
        'api-library' => '/api/v1/library',
        'api-lists' => '/api/v1/lists',
        'api-calendar' => '/api/v1/calendar',
        'api-notifications' => '/api/v1/notifications',
    ];

    $list = DB::table('lists')->where('user_id', $user->id)->orderBy('id')->value('id');

    if ($list !== null) {
        $pages['list'] = "/user/{$user->username}/lists/{$list}";
        $pages['api-list'] = "/api/v1/lists/{$list}";
    }

    foreach (['show', 'movie', 'game', 'book', 'manga', 'comic'] as $type) {
        $item = libraryItem($user, $type);

        if ($item !== null) {
            $pages[$type] = "/{$type}/{$item->provider_ref}";
            $pages["api-{$type}"] = "/api/v1/media/{$type}/{$item->provider_ref}";
        }

        if ($type === 'show' && $item !== null) {
            $pages['season'] = "/show/{$item->provider_ref}/season/1";
            $pages['episode'] = "/show/{$item->provider_ref}/season/1/episode/1";
        }
    }

    $person = DB::table('follows')->join('people', 'people.id', '=', 'follows.person_id')
        ->where('follows.user_id', $user->id)->orderBy('follows.id')->first(['people.provider', 'people.provider_ref']);

    if ($person !== null) {
        $pages['person'] = "/person/{$person->provider}/{$person->provider_ref}";
    }

    foreach (['company', 'network'] as $kind) {
        $company = DB::table('follows')->join('companies', 'companies.id', '=', 'follows.company_id')
            ->where('follows.user_id', $user->id)->where('companies.kind', $kind)->orderBy('follows.id')->first(['companies.provider', 'companies.provider_ref']);

        if ($company !== null) {
            $pages[$kind] = "/{$kind}/{$company->provider}/{$company->provider_ref}";
        }
    }

    // A collection is an ownerless list keyed "collection:{provider}:{ref}".
    $collection = DB::table('follows')->join('lists', 'lists.id', '=', 'follows.list_id')
        ->where('follows.user_id', $user->id)->orderBy('follows.id')->value('lists.system_key');

    if (is_string($collection) && substr_count($collection, ':') >= 2) {
        [, $provider, $ref] = explode(':', $collection, 3);
        $pages['collection'] = "/collection/{$provider}/{$ref}";
    }

    return $pages;
}

/**
 * The first title of this type the snapshot account has played — for a show, the show
 * an episode played belongs to. Plays are the library; picked in id order so
 * every take visits the same one.
 */
function libraryItem(User $user, string $type): ?MediaItem
{
    $played = DB::table('plays')->where('user_id', $user->id)->select('media_item_id');

    $ids = match ($type) {
        'show' => DB::table('media_items as seasons')
            ->whereIn('seasons.id', DB::table('media_items as episodes')->whereIn('episodes.id', $played)->select('episodes.parent_id'))
            ->select('seasons.parent_id'),
        default => $played,
    };

    return MediaItem::query()->where('type', $type)->whereIn('id', $ids)->orderBy('id')->first();
}

/**
 * An Inertia page with its deferred props filled in, the way the browser
 * would load it; a JSON endpoint or a download as it comes.
 *
 * @return array{status: int, bodies: list<string>, component?: string, props?: mixed, body?: mixed}
 */
function inertiaPage(HttpKernel $kernel, User $user, string $url): array
{
    $response = send($kernel, $user, Request::create($url));

    // A page that moved is read where it went.
    if ($response->isRedirection() && $response->headers->has('Location')) {
        $response = send($kernel, $user, Request::create((string) parse_url((string) $response->headers->get('Location'), PHP_URL_PATH)));
    }

    $body = content($response);
    $status = $response->getStatusCode();
    $bodies = [$body];

    // The page object rides a JSON script element; an older Inertia wrote it
    // into an attribute, which an earlier snapshot was taken from.
    if (preg_match('#<script data-page="app" type="application/json">(.*?)</script>#s', $body, $match) === 1) {
        $page = json_decode($match[1], true);
    } elseif (preg_match('/data-page="([^"]*)"/', $body, $match) === 1) {
        $page = json_decode(html_entity_decode($match[1], ENT_QUOTES | ENT_HTML5), true);
    } else {
        return ['status' => $status, 'bodies' => $bodies, 'body' => json_decode($body, true) ?? mb_substr($body, 0, 2000)];
    }

    foreach ($page['deferredProps'] ?? [] as $keys) {
        $partial = Request::create($url, 'GET', [], [], [], [
            'HTTP_X_INERTIA' => 'true',
            'HTTP_X_INERTIA_VERSION' => (string) ($page['version'] ?? ''),
            'HTTP_X_INERTIA_PARTIAL_COMPONENT' => $page['component'],
            'HTTP_X_INERTIA_PARTIAL_DATA' => implode(',', $keys),
        ]);
        $deferred = content(send($kernel, $user, $partial));
        $bodies[] = $deferred;
        $loaded = json_decode($deferred, true);
        $page['props'] = array_replace($page['props'], $loaded['props'] ?? []);
    }

    unset($page['deferredProps']);

    return ['status' => $status, 'bodies' => $bodies, 'component' => $page['component'], 'props' => $page['props']];
}

/** @return array{status: int, bodies: list<string>, body: mixed} */
function apiPage(HttpKernel $kernel, string $url, string $token): array
{
    $request = Request::create($url, 'GET', [], [], [], ['HTTP_AUTHORIZATION' => "Bearer {$token}", 'HTTP_ACCEPT' => 'application/json']);
    $response = $kernel->handle($request);
    $kernel->terminate($request, $response);
    $body = content($response);

    return ['status' => $response->getStatusCode(), 'bodies' => [$body], 'body' => json_decode($body, true)];
}

function send(HttpKernel $kernel, User $user, Request $request): Response
{
    // An API request leaves Sanctum as the default guard; a page signs in through the session one.
    Auth::shouldUse('web');
    Auth::guard('web')->setUser($user);
    // Ziggy declares its route map in a process's first page only and merges
    // into it on later ones; each visit here stands for a browser's first
    // load, which carries the declaration.
    BladeRouteGenerator::$generated = false;
    $response = $kernel->handle($request);
    $kernel->terminate($request, $response);

    return $response;
}

function content(Response $response): string
{
    if (! $response instanceof StreamedResponse) {
        return (string) $response->getContent();
    }

    ob_start();
    $response->sendContent();

    return (string) ob_get_clean();
}

/** Drops what changes per request; leaves everything the page shows. */
function normalize(mixed $value): mixed
{
    if (! is_array($value)) {
        return $value;
    }

    foreach (VOLATILE as $key) {
        unset($value[$key]);
    }

    return array_map(normalize(...), $value);
}

function diff(string $a, string $b): void
{
    $left = SNAPSHOTS."/{$a}";
    $right = SNAPSHOTS."/{$b}";
    (is_dir($left) && is_dir($right)) || exit('Both takes must exist under '.SNAPSHOTS."\n");

    $names = array_unique([...array_map('basename', glob("{$left}/*.json")), ...array_map('basename', glob("{$right}/*.json"))]);
    sort($names);
    $changed = 0;

    foreach ($names as $file) {
        if ($file === '_timings.json') {
            continue;
        }

        $old = is_file("{$left}/{$file}") ? json_decode((string) file_get_contents("{$left}/{$file}"), true) : null;
        $new = is_file("{$right}/{$file}") ? json_decode((string) file_get_contents("{$right}/{$file}"), true) : null;
        $differences = [];
        compare($old, $new, '', $differences);

        if ($differences !== []) {
            $changed++;
            echo "\n".basename($file, '.json').' — '.count($differences)." difference(s)\n";

            foreach (array_slice($differences, 0, 25) as $line) {
                echo "  {$line}\n";
            }

            count($differences) > 25 && print '  … '.(count($differences) - 25)." more\n";
        }
    }

    echo "\n".($changed === 0 ? 'No page changed.' : "{$changed} page(s) changed.")."\n";
    timings($left, $right);
}

/** @param  list<string>  $out */
function compare(mixed $old, mixed $new, string $path, array &$out): void
{
    if (is_array($old) && is_array($new)) {
        foreach (array_unique([...array_keys($old), ...array_keys($new)]) as $key) {
            $here = $path === '' ? (string) $key : "{$path}.{$key}";

            match (true) {
                ! array_key_exists($key, $new) => $out[] = "- {$here}",
                ! array_key_exists($key, $old) => $out[] = "+ {$here} = ".preview($new[$key]),
                default => compare($old[$key], $new[$key], $here, $out),
            };
        }

        return;
    }

    if ($old !== $new) {
        $out[] = "~ {$path}: ".preview($old).' → '.preview($new);
    }
}

function preview(mixed $value): string
{
    $json = (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    return mb_strlen($json) > 80 ? mb_substr($json, 0, 77).'…' : $json;
}

/** Time, query count and response size per page, before → after; informative, never a failure on its own. */
function timings(string $left, string $right): void
{
    $read = fn (string $dir): array => is_file("{$dir}/_timings.json") ? (array) json_decode((string) file_get_contents("{$dir}/_timings.json"), true) : [];
    $old = $read($left);
    $new = $read($right);

    echo "\n".str_pad('page', 28).str_pad('ms', 16).str_pad('queries', 16).'KB'."\n";
    $kilobytes = fn (?int $bytes): string => $bytes === null ? '–' : (string) round($bytes / 1024);

    foreach ($new as $page => $now) {
        $was = $old[$page] ?? [];
        printf("%-28s%-16s%-16s%s\n", $page, ($was['ms'] ?? '–').' → '.$now['ms'], ($was['queries'] ?? '–').' → '.$now['queries'], $kilobytes($was['bytes'] ?? null).' → '.$kilobytes($now['bytes'] ?? null));
    }
}

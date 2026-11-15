<?php

/*
 * The regression net: what the app hands to its pages and API, as the snapshot
 * account (USER_ID) sees it, frozen so two runs can be compared field by field.
 *
 *   php scripts/snapshot.php base          copy today's database and provider cache as the frozen base
 *   php scripts/snapshot.php take <label>  render every page from a fresh copy of the base
 *   php scripts/snapshot.php diff <a> <b>  what changed between two takes
 *   php scripts/snapshot.php restore       the snapshot account's backup imported into an empty account: time, queries, rows
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
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

const ROOT = __DIR__.'/..';
const SNAPSHOTS = ROOT.'/storage/snapshots';
const BASE = SNAPSHOTS.'/base';
const USER_ID = 1;

/** Keys that change on every request and say nothing about the page. */
const VOLATILE = ['errors', 'flash', 'ziggy', 'csrf_token', 'version', 'encryptHistory', 'clearHistory'];

require ROOT.'/vendor/autoload.php';

$command = $argv[1] ?? '';

match ($command) {
    'base' => makeBase(),
    'take' => take($argv[2] ?? exit("Usage: php scripts/snapshot.php take <label>\n")),
    'diff' => diff($argv[2] ?? '', $argv[3] ?? exit("Usage: php scripts/snapshot.php diff <a> <b>\n")),
    'restore' => restore(),
    default => exit("Usage: php scripts/snapshot.php base | take <label> | diff <a> <b> | restore\n"),
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

    // A throwaway copy of the base, so a take never changes the base or the live data.
    $work = sys_get_temp_dir().'/snapshot-'.uniqid();
    mkdir("{$work}/cache", 0777, true);
    copy(BASE.'/database.sqlite', "{$work}/database.sqlite");
    passthru('cp -r '.escapeshellarg(BASE.'/cache').'/. '.escapeshellarg("{$work}/cache"));

    $app = boot($work);

    $meta = json_decode((string) file_get_contents(BASE.'/meta.json'), true);
    CarbonImmutable::setTestNow($meta['frozenAt']);
    Carbon::setTestNow($meta['frozenAt']);

    Http::preventStrayRequests();
    Http::fake(['*' => Http::failedConnection()]);
    Sleep::fake();
    Queue::fake();
    Mail::fake();

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

    $work = sys_get_temp_dir().'/snapshot-'.uniqid();
    mkdir("{$work}/cache", 0777, true);
    copy(BASE.'/database.sqlite', "{$work}/database.sqlite");
    passthru('cp -r '.escapeshellarg(BASE.'/cache').'/. '.escapeshellarg("{$work}/cache"));

    boot($work);

    $meta = json_decode((string) file_get_contents(BASE.'/meta.json'), true);
    CarbonImmutable::setTestNow($meta['frozenAt']);
    Carbon::setTestNow($meta['frozenAt']);

    Http::preventStrayRequests();
    Http::fake(['*' => Http::failedConnection()]);
    Sleep::fake();
    Queue::fake();
    Mail::fake();

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
    if (is_file(BASE.'/meta.json')) {
        $meta = json_decode((string) file_get_contents(BASE.'/meta.json'), true);
        CarbonImmutable::setTestNow($meta['frozenAt']);
        Carbon::setTestNow($meta['frozenAt']);
    }

    $app->make(ConsoleKernel::class)->call('migrate', ['--force' => true]);
    // As the container's boot does after migrating.
    $app->make(ConsoleKernel::class)->call('vide:library:rebuild');

    return $app;
}

/**
 * Every page, in the same order each time (a page can leave a trace the next
 * one shows, like "recently viewed"), handed to $onPage with its timing.
 *
 * @param  callable(string, array<string, mixed>, array{ms: int, queries: int}): void  $onPage
 */
function visitPages(Application $app, callable $onPage): void
{
    $user = User::query()->findOrFail(USER_ID);
    $token = $user->createToken('snapshot');
    $kernel = $app->make(HttpKernel::class);

    foreach (pages($user) as $name => $url) {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $started = hrtime(true);

        $page = str_starts_with($url, '/api/') ? apiPage($kernel, $url, $token->plainTextToken) : inertiaPage($kernel, $user, $url);

        $onPage($name, $page, ['ms' => (int) round((hrtime(true) - $started) / 1e6), 'queries' => count(DB::getQueryLog())]);
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
        'suggestions' => '/suggestions',
        'suggestions-released' => '/suggestions/released-since-added',
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
        'profile-following' => "/user/{$username}/following",
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
 * @return array{status: int, component?: string, props?: mixed, body?: mixed}
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

    // The page object rides a JSON script element; an older Inertia wrote it
    // into an attribute, which an earlier snapshot was taken from.
    if (preg_match('#<script data-page="app" type="application/json">(.*?)</script>#s', $body, $match) === 1) {
        $page = json_decode($match[1], true);
    } elseif (preg_match('/data-page="([^"]*)"/', $body, $match) === 1) {
        $page = json_decode(html_entity_decode($match[1], ENT_QUOTES | ENT_HTML5), true);
    } else {
        return ['status' => $status, 'body' => json_decode($body, true) ?? mb_substr($body, 0, 2000)];
    }

    foreach ($page['deferredProps'] ?? [] as $keys) {
        $partial = Request::create($url, 'GET', [], [], [], [
            'HTTP_X_INERTIA' => 'true',
            'HTTP_X_INERTIA_VERSION' => (string) ($page['version'] ?? ''),
            'HTTP_X_INERTIA_PARTIAL_COMPONENT' => $page['component'],
            'HTTP_X_INERTIA_PARTIAL_DATA' => implode(',', $keys),
        ]);
        $loaded = json_decode(content(send($kernel, $user, $partial)), true);
        $page['props'] = array_replace($page['props'], $loaded['props'] ?? []);
    }

    unset($page['deferredProps']);

    return ['status' => $status, 'component' => $page['component'], 'props' => $page['props']];
}

/** @return array{status: int, body: mixed} */
function apiPage(HttpKernel $kernel, string $url, string $token): array
{
    $request = Request::create($url, 'GET', [], [], [], ['HTTP_AUTHORIZATION' => "Bearer {$token}", 'HTTP_ACCEPT' => 'application/json']);
    $response = $kernel->handle($request);
    $kernel->terminate($request, $response);

    return ['status' => $response->getStatusCode(), 'body' => json_decode(content($response), true)];
}

function send(HttpKernel $kernel, User $user, Request $request): Response
{
    // An API request leaves Sanctum as the default guard; a page signs in through the session one.
    Auth::shouldUse('web');
    Auth::guard('web')->setUser($user);
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

/** Time and query count per page, before → after; informative, never a failure on its own. */
function timings(string $left, string $right): void
{
    $read = fn (string $dir): array => is_file("{$dir}/_timings.json") ? (array) json_decode((string) file_get_contents("{$dir}/_timings.json"), true) : [];
    $old = $read($left);
    $new = $read($right);

    echo "\n".str_pad('page', 28).str_pad('ms', 16).'queries'."\n";

    foreach ($new as $page => $now) {
        $was = $old[$page] ?? ['ms' => null, 'queries' => null];
        printf("%-28s%-16s%s\n", $page, ($was['ms'] ?? '–').' → '.$now['ms'], ($was['queries'] ?? '–').' → '.$now['queries']);
    }
}

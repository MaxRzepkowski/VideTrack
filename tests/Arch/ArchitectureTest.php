<?php

use App\Catalog\Data\MediaDetails;
use App\Catalog\Data\MediaSummary;

/*
 * The code's dependency and shape rules a machine can check: who may use
 * whom, and what an action looks like. Each rule's name is the rule. A class on an `ignoring()` list
 * breaks its rule today and says why: known debt, or the reason it stays. The
 * lists only shrink; new code never joins them.
 */

/** Every catalog module (TMDB, IGDB…), not the HTTP plumbing they share. */
function providerModules(): array
{
    $modules = [];

    foreach (glob(dirname(__DIR__, 2).'/app/Catalog/Providers/*', GLOB_ONLYDIR) ?: [] as $dir) {
        if (basename($dir) !== 'Concerns') {
            $modules[] = 'App\\Catalog\\Providers\\'.basename($dir);
        }
    }

    return $modules;
}

arch('the core never reaches a concrete provider — only modules, integrations and their service providers do')
    ->expect(providerModules())
    ->toOnlyBeUsedIn(['App\Catalog\Providers', 'App\Integrations', 'App\Providers']);

arch('the catalog knows nothing of the library, integrations or the web')
    ->expect('App\Catalog')
    ->not->toUse(['App\Library', 'App\Integrations', 'App\Actions', 'App\Users', 'App\Http', 'App\Jobs', 'App\Notifications']);

/** @return list<string> */
function actionClasses(): array
{
    $root = dirname(__DIR__, 2);
    $classes = [];
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator("{$root}/app/Actions", FilesystemIterator::SKIP_DOTS));

    foreach ($files as $file) {
        $classes[] = 'App\\'.str_replace(['/', '.php'], ['\\', ''], substr($file->getPathname(), strlen("{$root}/app/")));
    }

    sort($classes);

    return $classes;
}

arch('an action is final readonly with execute() as its only public method')
    ->expect(actionClasses())
    ->toBeFinal()
    ->toBeReadonly()
    ->toHaveMethod('execute')
    ->not->toHavePublicMethodsBesides(['__construct', 'execute']);

arch('the domain takes values, never an HTTP request')
    ->expect('Illuminate\Http\Request')
    // A scrobble source is the parser of an incoming webhook: the request is its input.
    ->toOnlyBeUsedIn(['App\Http', 'App\Providers', 'App\Integrations\Scrobble', 'App\Integrations\Plex', 'App\Integrations\Jellyfin', 'App\Integrations\Emby', 'App\Integrations\Tautulli', 'App\Integrations\Generic']);

arch('the container is asked for things only at the edges')
    ->expect(['app', 'resolve'])
    ->toOnlyBeUsedIn(['App\Providers', 'App\Http\Requests']);

arch('no debugging leftovers, and env() only in config')
    ->expect(['dd', 'dump', 'ray', 'var_dump', 'die', 'env'])
    ->not->toBeUsed();

arch('the environment is read in config alone')
    ->expect('getenv')
    // The TypeScript export is a dev tool: the check points it at a scratch
    // directory through the environment.
    ->toOnlyBeUsedIn('App\Providers\TypeScriptServiceProvider');

arch('a model holds no domain logic: no actions, jobs or web')
    ->expect('App\Models')
    ->not->toUse(['App\Actions', 'App\Jobs', 'App\Http']);

arch('integrations never reach into controllers')
    ->expect('App\Integrations')
    ->not->toUse('App\Http\Controllers');

arch('catalog data is final and readonly')
    ->expect('App\Catalog\Data')
    ->classes()
    ->toBeFinal()
    ->toBeReadonly();

arch('a closed set of values is an enum')
    ->expect('App\Enums')
    ->toBeEnums();

arch('support is the floor: it knows nothing above it')
    ->expect('App\Support')
    ->not->toUse(['App\Catalog', 'App\Library', 'App\Tracking', 'App\Integrations', 'App\Actions', 'App\Users', 'App\Http', 'App\Jobs', 'App\Models', 'App\Tasks', 'App\Notifications']);

arch('a shared enum is a value, not a door to a module')
    ->expect('App\Enums')
    ->not->toUse(['App\Catalog', 'App\Library', 'App\Tracking', 'App\Integrations', 'App\Actions', 'App\Users', 'App\Http', 'App\Jobs', 'App\Models', 'App\Tasks'])
    // Known debt: the up-next order belongs with the shelf it sorts.
    ->ignoring('App\Enums\UpNextSort');

arch('the catalog runs no tasks and reads no bells')
    ->expect('App\Catalog')
    ->not->toUse(['App\Tasks', 'App\Models\Follow'])
    // Known debt: a creator table's sweep task and its followers are asked of the library.
    ->ignoring(['App\Catalog\Creators\CreatorTable', 'App\Catalog\Creators\Creators']);

arch('tracking reads and decides; it neither writes through actions nor queues jobs')
    ->expect('App\Tracking')
    ->not->toUse(['App\Actions', 'App\Jobs'])
    // Known debt: the sending and syncing move to their callers. EpisodeTimes
    // stays: a show page has it queue the air-time fetch, one of the reads allowed to write.
    ->ignoring(['App\Tracking\Surfaces\TodaysReleases', 'App\Tracking\Creators\CreatorTitleSource', 'App\Tracking\EpisodeTimes']);

arch('an action hands back data, never an HTTP resource')
    ->expect('App\Actions')
    ->not->toUse('App\Http\Resources')
    // Known debt: the feed's rows are shaped where the page reads them.
    ->ignoring('App\Actions\BuildNotificationFeed');

// A model is its row: relations, casts, scopes and plain questions about its
// own state. Turning a row into a card or a page's details is the catalog's
// work (MediaItemSummaries, CardSnapshot), so every card of a kind is built
// in one place.
arch('a model does not build cards')
    ->expect('App\Models')
    ->not->toUse([MediaSummary::class, MediaDetails::class]);

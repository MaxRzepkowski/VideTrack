<?php

namespace App\Http\Controllers\Media;

use App\Actions\Media\RefreshCollection;
use App\Actions\Media\RefreshCreator;
use App\Actions\Media\RetireGoneParts;
use App\Catalog\Contracts\SupportsCollections;
use App\Catalog\Creators\CreatorKind;
use App\Catalog\Creators\CreatorPages;
use App\Catalog\Creators\CreatorRef;
use App\Catalog\Hierarchy;
use App\Catalog\ProviderCacheContext;
use App\Catalog\ProviderRegistry;
use App\Catalog\Relations\RelationStore;
use App\Catalog\TitleStore;
use App\Http\Controllers\Controller;
use App\Http\Requests\Media\RefreshMediaRequest;
use App\Jobs\RefreshTitleTies;
use App\Tracking\EpisodeTimes;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\App;

/**
 * On-demand refresh triggered from an entity page — the same provider fetch the
 * scheduled tasks run, but for a single target and with the response cache
 * bypassed (ProviderCacheContext) so the reload shows genuinely fresh data
 * instead of the day-long cached copy. Runs synchronously: a page reload after
 * back() re-renders with the updated content. What comes from slower sources
 * (air times, ties to other titles) is queued and shows a little later.
 */
class RefreshController extends Controller
{
    public function media(
        RefreshMediaRequest $request,
        ProviderCacheContext $cache,
        TitleStore $persist,
        Hierarchy $hierarchy,
        RetireGoneParts $retireParts,
        EpisodeTimes $episodeTimes,
        RelationStore $relations,
    ): RedirectResponse {
        // Resolve the (already persisted) target, then climb to its root:
        // refresh() re-fetches the whole tree with no short-circuit, so
        // refreshing a season or episode refreshes its show.
        $item = $persist->persist($request->mediaType(), $request->mediaRef(), App::getLocale());

        $root = $hierarchy->rootOf($item);

        /** @var list<int> $vanished */
        $vanished = $cache->fresh(fn (): array => $persist->refresh($root, App::getLocale()));

        $retireParts->execute($root, $vanished);

        // What the title's page shows from other sources follows in the
        // background: a show's air times, and the title's ties and franchise.
        $episodeTimes->afterRefresh($root, scheduleChanged: true);

        if ($relations->sourceIdOf($root) !== null) {
            RefreshTitleTies::dispatch($root->id);
        }

        return back();
    }

    public function collection(
        string $provider,
        string $ref,
        ProviderCacheContext $cache,
        ProviderRegistry $providers,
        RefreshCollection $refresh,
    ): RedirectResponse {
        $source = $providers->find($provider);

        abort_unless($source instanceof SupportsCollections, 404);

        $cache->fresh(fn () => $refresh->execute($provider, $source->collection($ref, App::getLocale()), App::getLocale()));

        return back();
    }

    /** A person, a studio / publisher or a network: the route says which. */
    public function creator(
        string $provider,
        string $ref,
        string $kind,
        ProviderCacheContext $cache,
        CreatorPages $pages,
        RefreshCreator $refresh,
    ): RedirectResponse {
        $creator = new CreatorRef(CreatorKind::from($kind), $provider, $ref);

        abort_unless($pages->serves($creator), 404);

        $cache->fresh(fn () => $refresh->execute($creator, App::getLocale()));

        return back();
    }
}

<?php

namespace App\Http\Controllers\Media;

use App\Actions\Media\ResolveEventsShowing;
use App\Actions\Media\ResolveRelatedTitles;
use App\Actions\Media\ResolveTitleCollection;
use App\Actions\Media\ResolveTitleDetails;
use App\Catalog\Contracts\SupportsEvents;
use App\Catalog\Contracts\SupportsSimilarTitles;
use App\Catalog\Data\CollectionBannerData;
use App\Catalog\MediaTypeRegistry;
use App\Catalog\ProviderCalls;
use App\Catalog\ProviderRegistry;
use App\Catalog\Relations\TieRules;
use App\Http\Controllers\Controller;
use App\Library\LibraryStates;
use App\Library\RecentViews;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The page of a title with nothing beneath it and nothing beside its details
 * (a book, a game, a manga, a comic). The path names the type; each type has
 * its own page component, `media/{Type}Details`, which reads the details
 * under the type's name.
 */
class TitleDetailsController extends Controller
{
    public function __invoke(
        #[CurrentUser] User $viewer,
        MediaTypeRegistry $types,
        ProviderRegistry $providers,
        ResolveTitleDetails $resolve,
        ResolveTitleCollection $titleCollection,
        ResolveEventsShowing $eventsShowing,
        LibraryStates $states,
        RecentViews $recentViews,
        TieRules $tieRules,
        ResolveRelatedTitles $relatedTitles,
        string $type,
        string $ref,
    ): Response {
        // A type exists only while its module is configured (a Hardcover
        // token, IGDB credentials…), and only a root with nothing beneath it
        // is a page of this kind.
        abort_unless($types->has($type) && $types->get($type)->isRoot() && $types->get($type)->childType === null, 404);

        $title = $resolve->execute($viewer, $type, $ref, App::getLocale());
        $details = $title->details;

        $recentViews->record($viewer, $details->summary);

        $provider = $providers->forType($types->get($type));
        $related = $relatedTitles->execute($details);

        $props = [
            $type => $details,
            'fromLibrary' => $title->fromLibrary,
            'removedAtSource' => $title->removedAtSource,
            // The titles its provider ties to it with the details (a manga's sequel).
            'relatedTitles' => $tieRules->fromProvider($details->ties),
            // Its works in other media, as the background task stored them.
            'otherMedia' => $related->otherMedia,
            // The series or saga the title belongs to, where its provider curates one;
            // from the library alone the provider is not asked.
            'collection' => $title->fromLibrary
                ? null
                : fn (): ?CollectionBannerData => $titleCollection->execute($provider, $details, App::getLocale()),
            'recentlyViewed' => fn (): array => $recentViews->recent($viewer, except: $details->summary),
            'userStates' => fn (): array => [...$states->resolveForDetails($viewer, $details), ...$states->resolve($viewer, $related->cards())],
        ];

        // Every event that showed it (a game at Summer Game Fest), long past ones
        // too, so only its provider knows them: asked after the page is drawn,
        // and only of a provider that lists events at all.
        if ($provider instanceof SupportsEvents && ! $title->fromLibrary) {
            $props['shownAt'] = Inertia::defer(fn (): array => $eventsShowing->execute($provider, $details->summary->ref));
        }

        // Titles like this one, where the provider names them apart from the
        // details (a second request): asked after the page is drawn. Their cards
        // carry the action bar, but `userStates` resolves before they are known,
        // so their share arrives with them; `once` keeps it to one ask.
        if ($provider instanceof SupportsSimilarTitles && ! $title->fromLibrary) {
            $similar = fn (): array => once(fn (): array => (new ProviderCalls)->attempt(fn (): array => $provider->similarTo($type, $ref, App::getLocale()), []));
            $props['similar'] = Inertia::defer($similar);
            $props['deferredUserStates'] = Inertia::defer(fn (): array => $states->resolve($viewer, $similar()));
        }

        return Inertia::render('media/'.Str::studly($type).'Details', $props);
    }
}

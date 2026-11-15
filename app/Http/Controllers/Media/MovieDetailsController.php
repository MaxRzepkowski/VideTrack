<?php

namespace App\Http\Controllers\Media;

use App\Actions\Media\ResolveRelatedTitles;
use App\Actions\Media\ResolveSourceBooks;
use App\Actions\Media\ResolveTitleCollection;
use App\Actions\Media\ResolveTitleDetails;
use App\Catalog\Data\CollectionBannerData;
use App\Catalog\Data\WatchAvailability;
use App\Catalog\MediaTypeRegistry;
use App\Catalog\ProviderRegistry;
use App\Catalog\WhereToWatch;
use App\Http\Controllers\Controller;
use App\Library\LibraryStates;
use App\Library\RecentViews;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Support\Facades\App;
use Inertia\Inertia;
use Inertia\Response;

class MovieDetailsController extends Controller
{
    public function __invoke(
        #[CurrentUser] User $viewer,
        MediaTypeRegistry $types,
        ProviderRegistry $providers,
        LibraryStates $states,
        ResolveTitleDetails $resolve,
        WhereToWatch $whereToWatch,
        ResolveTitleCollection $titleCollection,
        ResolveSourceBooks $sourceBooks,
        RecentViews $recentViews,
        ResolveRelatedTitles $relatedTitles,
        string $ref,
    ): Response {
        $provider = $providers->forType($types->get('movie'));

        $title = $resolve->execute($viewer, 'movie', $ref, App::getLocale());
        $details = $title->details;
        // Every provider-backed prop below talks to the provider that just
        // failed, so in that mode they are not even attempted.
        $fromLibrary = $title->fromLibrary;

        $recentViews->record($viewer, $details->summary);

        // Read by two deferred props that land in the same follow-up request;
        // `once` keeps it to a single Wikidata round-trip.
        $books = fn (): ?array => once(fn (): ?array => $sourceBooks->execute($details, App::getLocale()));

        $related = $relatedTitles->execute('movie', $ref);

        return Inertia::render('media/MovieDetails', [
            'movie' => $details,
            'fromLibrary' => $fromLibrary,
            'removedAtSource' => $title->removedAtSource,
            'recentlyViewed' => fn (): array => $recentViews->recent($viewer, except: $details->summary),
            'collection' => $fromLibrary
                ? null
                : fn (): ?CollectionBannerData => $titleCollection->execute($provider, $details, App::getLocale()),
            // Deferred (Wikidata bridge): the source book(s) resolved through
            // our own book provider — P144 → literary work → ISBN lookup. The
            // SPARQL round-trip must never block the render; the section
            // appears once the follow-up request lands.
            'sourceBooks' => Inertia::defer($books),
            // The book cards carry the shared action bar, but `userStates`
            // resolves with the render, before the books are known — their
            // share arrives with them.
            'deferredUserStates' => Inertia::defer(fn (): array => $states->resolve($viewer, $books() ?? [])),
            'watchProviders' => $fromLibrary
                ? null
                : fn (): ?WatchAvailability => $whereToWatch->forViewer($provider, 'movie', $ref, $viewer, App::getLocale()),
            // Worldwide, not the viewer's region: the Releases block uses it to
            // tell "no digital date filed yet" apart from "still cinema-only".
            // Reads the same cached response as watchProviders above.
            'offerRegions' => $fromLibrary
                ? []
                : fn (): array => $whereToWatch->regionsWithOffers($provider, 'movie', $ref),
            // Spin-offs and what follows, as the background task stored them.
            'relatedTitles' => $related,
            'userStates' => fn (): array => [...$states->resolveForDetails($viewer, $details), ...$states->resolve($viewer, array_column($related, 'media'))],
        ]);
    }
}

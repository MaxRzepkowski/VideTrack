<?php

namespace App\Http\Controllers\Media;

use App\Actions\Media\ResolveRelatedTitles;
use App\Actions\Media\ResolveSourceBooks;
use App\Actions\Media\ResolveSourceManga;
use App\Actions\Media\ResolveTitleDetails;
use App\Actions\Media\ResolveTotalRuntime;
use App\Catalog\Contracts\SupportsEpisodeRatings;
use App\Catalog\Data\WatchAvailability;
use App\Catalog\MediaTypeRegistry;
use App\Catalog\ProviderCalls;
use App\Catalog\ProviderRegistry;
use App\Catalog\WhereToWatch;
use App\Http\Controllers\Controller;
use App\Library\LibraryStates;
use App\Library\RecentViews;
use App\Models\User;
use App\Tracking\EpisodeTimes;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Support\Facades\App;
use Inertia\Inertia;
use Inertia\Response;

class ShowDetailsController extends Controller
{
    public function __invoke(
        #[CurrentUser] User $viewer,
        MediaTypeRegistry $types,
        ProviderRegistry $providers,
        LibraryStates $states,
        ResolveTitleDetails $resolve,
        WhereToWatch $whereToWatch,
        ResolveSourceBooks $sourceBooks,
        ResolveSourceManga $sourceManga,
        EpisodeTimes $episodeTimes,
        ResolveTotalRuntime $totalRuntime,
        RecentViews $recentViews,
        ResolveRelatedTitles $relatedTitles,
        string $ref,
    ): Response {
        $provider = $providers->forType($types->get('show'));

        $title = $resolve->execute($viewer, 'show', $ref, App::getLocale());
        $details = $title->details;
        $fromLibrary = $title->fromLibrary;

        $recentViews->record($viewer, $details->summary);

        $airing = $details->airingEpisodes();

        // Each read by two deferred props that land in the same follow-up
        // request; `once` keeps it to a single Wikidata round-trip apiece.
        $books = fn (): ?array => once(fn (): ?array => $sourceBooks->execute($details, App::getLocale()));
        $manga = fn (): ?array => once(fn (): ?array => $sourceManga->execute($details, App::getLocale()));

        $related = $relatedTitles->execute('show', $ref);

        return Inertia::render('media/ShowDetails', [
            'show' => $details,
            'fromLibrary' => $fromLibrary,
            'removedAtSource' => $title->removedAtSource,
            'recentlyViewed' => fn (): array => $recentViews->recent($viewer, except: $details->summary),
            // Watch time of the whole series (every episode summed). Instant
            // for a tracked show — the stored tree already carries the total —
            // but an untracked one has to fetch its episodes, so the value
            // arrives with the follow-up request instead of blocking the page.
            'totalRuntime' => Inertia::defer(fn (): ?int => (new ProviderCalls)->attempt(
                fn (): ?int => $totalRuntime->execute('show', $ref, $details, App::getLocale()),
                null,
            )),
            'watchProviders' => $fromLibrary
                ? null
                : fn (): ?WatchAvailability => $whereToWatch->forViewer($provider, 'show', $ref, $viewer, App::getLocale()),
            // Deferred (Wikidata bridge): the source book(s) resolved through
            // our own book provider — P144 → literary work → ISBN lookup.
            'sourceBooks' => Inertia::defer($books),
            // Deferred (Wikidata bridge): the source manga for an anime —
            // P144 → MyAnimeList manga id (P4087) → our manga provider.
            'sourceManga' => Inertia::defer($manga),
            // The source cards carry the shared action bar, but `userStates`
            // resolves with the render, before the sources are known — their
            // share arrives with them.
            'deferredUserStates' => Inertia::defer(fn (): array => $states->resolve($viewer, [...$books() ?? [], ...$manga() ?? []])),
            // Deferred: the chart data arrives after the page renders; a
            // provider without the capability (or an outage) hides the chart.
            'episodeRatings' => Inertia::defer(function () use ($provider, $ref): array {
                if (! $provider instanceof SupportsEpisodeRatings) {
                    return [];
                }

                return (new ProviderCalls)->attempt(fn (): array => $provider->episodeRatings($ref, App::getLocale()), []);
            }),
            // Deferred so TVmaze never blocks the render: the cards paint with
            // TMDB air dates, then the exact broadcast times arrive in a
            // follow-up request (empty for streaming / unmatched shows; a
            // cold schedule is warmed for the next visit).
            'episodeTimes' => Inertia::defer(function () use ($episodeTimes, $ref, $airing): array {
                if ($airing === []) {
                    return [];
                }

                $episodeTimes->warmIfCold($ref);

                return $episodeTimes->ofEpisodes($ref, $airing);
            }),
            // Spin-offs and what follows, as the background task stored them.
            'relatedTitles' => $related,
            'userStates' => fn (): array => [...$states->resolveForDetails($viewer, $details), ...$states->resolve($viewer, array_column($related, 'media'))],
        ]);
    }
}

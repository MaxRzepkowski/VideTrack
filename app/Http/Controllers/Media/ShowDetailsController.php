<?php

namespace App\Http\Controllers\Media;

use App\Actions\Media\ResolveRelatedTitles;
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

        $related = $relatedTitles->execute($details);

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
            // Spin-offs, what follows and its works in other media, as the background task stored them.
            'relatedTitles' => $related->universe,
            'otherMedia' => $related->otherMedia,
            'userStates' => fn (): array => [...$states->resolveForDetails($viewer, $details), ...$states->resolve($viewer, $related->cards())],
        ]);
    }
}

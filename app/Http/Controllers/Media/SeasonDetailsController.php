<?php

namespace App\Http\Controllers\Media;

use App\Actions\Media\ResolveTitleDetails;
use App\Actions\Media\ResolveTotalRuntime;
use App\Catalog\Data\WatchAvailability;
use App\Catalog\HierarchyPath;
use App\Catalog\MediaTypeRegistry;
use App\Catalog\ProviderRegistry;
use App\Catalog\WhereToWatch;
use App\Http\Controllers\Controller;
use App\Library\LibraryStates;
use App\Models\User;
use App\Tracking\EpisodeTimes;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Support\Facades\App;
use Inertia\Inertia;
use Inertia\Response;

class SeasonDetailsController extends Controller
{
    public function __invoke(
        #[CurrentUser] User $viewer,
        MediaTypeRegistry $types,
        ProviderRegistry $providers,
        LibraryStates $states,
        ResolveTitleDetails $resolve,
        HierarchyPath $path,
        EpisodeTimes $episodeTimes,
        ResolveTotalRuntime $totalRuntime,
        WhereToWatch $whereToWatch,
        string $ref,
        int $number,
    ): Response {
        // The route carries the parts; the provider composes the season ref.
        $seasonRef = $path->descend('show', $ref, [$number])->ref ?? abort(404);

        $provider = $providers->forType($types->get('season'));

        $title = $resolve->execute($viewer, 'season', $seasonRef, App::getLocale());
        $details = $title->details;
        $fromLibrary = $title->fromLibrary;

        return Inertia::render('media/SeasonDetails', [
            'season' => $details,
            'fromLibrary' => $fromLibrary,
            'removedAtSource' => $title->removedAtSource,
            // Watch time of the whole season — its episodes summed (they come
            // with the page, so no extra provider call). The stored tree
            // carries the same total, so the row survives the outage.
            'totalRuntime' => $fromLibrary
                ? $details->summary->totalRuntime
                : $totalRuntime->execute('season', $seasonRef, $details, App::getLocale()),
            'watchProviders' => $fromLibrary
                ? null
                : fn (): ?WatchAvailability => $whereToWatch->forViewer($provider, 'season', $seasonRef, $viewer, App::getLocale()),
            // Deferred so TVmaze never blocks the render: the page paints with
            // TMDB air dates, then Inertia fetches the exact broadcast times in
            // a follow-up request (empty for streaming / unmatched shows).
            'episodeTimes' => Inertia::defer(function () use ($episodeTimes, $ref, $number): array {
                $episodeTimes->warmIfCold($ref);

                return $episodeTimes->ofSeason($ref, $number);
            }),
            'userStates' => fn (): array => $states->resolveForDetails($viewer, $details),
        ]);
    }
}

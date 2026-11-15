<?php

namespace App\Http\Controllers\Media;

use App\Actions\Media\ResolveTitleDetails;
use App\Catalog\HierarchyPath;
use App\Http\Controllers\Controller;
use App\Library\LibraryStates;
use App\Models\User;
use App\Tracking\EpisodeTimes;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Support\Facades\App;
use Inertia\Inertia;
use Inertia\Response;

class EpisodeDetailsController extends Controller
{
    public function __invoke(
        #[CurrentUser] User $viewer,
        LibraryStates $states,
        ResolveTitleDetails $resolve,
        HierarchyPath $path,
        EpisodeTimes $episodeTimes,
        string $ref,
        int $number,
        int $episode,
    ): Response {
        // The route carries the parts; the provider composes the episode ref.
        $episodeRef = $path->descend('show', $ref, [$number, $episode])->ref ?? abort(404);

        $title = $resolve->execute($viewer, 'episode', $episodeRef, App::getLocale());
        $details = $title->details;

        return Inertia::render('media/EpisodeDetails', [
            'episode' => $details,
            'fromLibrary' => $title->fromLibrary,
            'removedAtSource' => $title->removedAtSource,
            // Deferred so TVmaze never blocks the render: the page paints with
            // the TMDB air date, then Inertia fills in the exact broadcast
            // instant (null for streaming / unmatched shows).
            'airTime' => Inertia::defer(function () use ($episodeTimes, $ref, $number, $episode): ?string {
                $episodeTimes->warmIfCold($ref);

                return $episodeTimes->ofSeason($ref, $number)[$episode] ?? null;
            }),
            'userStates' => fn (): array => $states->resolveForDetails($viewer, $details),
        ]);
    }
}

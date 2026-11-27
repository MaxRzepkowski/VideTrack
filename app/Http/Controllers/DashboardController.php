<?php

namespace App\Http\Controllers;

use App\Actions\Media\BuildWatchStats;
use App\Actions\Media\ResolveNowPlaying;
use App\Actions\Media\ResolveUpNext;
use App\Library\Data\PlayingEntry;
use App\Library\Data\UpNextEntry;
use App\Library\LibraryStates;
use App\Models\User;
use App\Tasks\Attention\AttentionRegistry;
use App\Tasks\Attention\Data\PageVisit;
use App\Tracking\Surfaces\CalendarDays;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(
        #[CurrentUser] User $viewer,
        ResolveUpNext $upNext,
        ResolveNowPlaying $nowPlaying,
        CalendarDays $calendar,
        BuildWatchStats $stats,
        LibraryStates $states,
        AttentionRegistry $attention,
        Request $request,
    ): Response {
        $entries = $upNext->execute($viewer);
        $playing = $nowPlaying->execute($viewer);

        $episodes = array_map(fn (UpNextEntry $entry) => $entry->episode, $entries);
        $playingMedia = array_map(fn (PlayingEntry $entry) => $entry->media, $playing);

        // Shows one episode away from running out: settling that finale swaps
        // their card for a completion card offering a rating, and the state
        // has to be known before the entry leaves. Only these few — resolving
        // every watched show would cost what Up Next itself avoids.
        $closing = array_map(
            fn (UpNextEntry $entry) => $entry->show,
            array_filter($entries, fn (UpNextEntry $entry) => $entry->closes !== null),
        );

        return Inertia::render('Dashboard', [
            'stats' => $stats->execute($viewer),
            'upNext' => $entries,
            'playing' => $playing,
            'calendar' => $calendar->nearest($viewer),
            'userStates' => $states->resolve($viewer, [...$playingMedia, ...$episodes, ...$closing]),
            // The admin's one word about the instance: the problems only. A
            // warning may stand on purpose and waits on the Instance page.
            'attention' => $viewer->isAdmin() ? $attention->problemCount(new PageVisit($request->root())) : 0,
        ]);
    }
}

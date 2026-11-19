<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Library\DeletePlay;
use App\Actions\Library\MarkWatched;
use App\Actions\Library\UnmarkWatched;
use App\Actions\Library\UpdatePlayAmount;
use App\Actions\Media\ResolveNextEpisode;
use App\Catalog\TitleStore;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\MediaRequest;
use App\Http\Requests\Api\V1\StorePlaysRequest;
use App\Http\Requests\Api\V1\UpdatePlayAmountRequest;
use App\Http\Resources\Api\V1\PlayResource;
use App\Http\Responses\PositionWrites;
use App\Http\Responses\PositionWritten;
use App\Library\AffectedStates;
use App\Models\Play;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * The user's history for one position. Reads the database alone — a position
 * nobody ever touched simply has no plays, which is not an error.
 */
class PlayController extends Controller
{
    /**
     * Every viewing / playthrough of the position, newest first.
     *
     * @return AnonymousResourceCollection<int, PlayResource>
     */
    public function index(#[CurrentUser] User $viewer, MediaRequest $request): AnonymousResourceCollection
    {
        $item = $request->storedItem();

        $plays = $item === null
            ? collect()
            : Play::query()
                ->historyOf($viewer, $item)
                ->with('watchCycle:id,number')
                ->get(['id', 'watch_cycle_id', 'watched_at', 'outcome', 'amount', 'source']);

        return PlayResource::collection($plays);
    }

    /**
     * Logs a completion. On a container this settles every unwatched aired
     * descendant, so `meta.created` says how many entries that turned out
     * to be. With `next_episode` the play lands on the one episode the
     * container stands at instead — which one is a server question.
     */
    public function store(
        #[CurrentUser] User $viewer,
        StorePlaysRequest $request,
        TitleStore $persist,
        MarkWatched $mark,
        ResolveNextEpisode $nextEpisode,
        PositionWrites $written,
    ): PositionWritten {
        $item = $persist->persist($request->mediaType(), $request->mediaRef(), App::getLocale());

        if ($request->nextEpisode()) {
            $item = $nextEpisode->execute($viewer, $item)
                ?? throw ValidationException::withMessages([
                    'next_episode' => __('common.this_title_has_no_episode_to'),
                ]);
        }

        $created = $mark->execute(
            $viewer,
            $item,
            $request->watchedAt(),
            $request->amount(),
            $request->attemptPolicy(),
        );

        return $written->of($viewer, $item, ['created' => $created], 201);
    }

    /**
     * Unmarks the whole position — every entry of the active cycle, down the
     * hierarchy. Deleting one viewing is DELETE /plays/{play}.
     */
    public function destroy(
        #[CurrentUser] User $viewer,
        MediaRequest $request,
        TitleStore $persist,
        UnmarkWatched $unmark,
        PositionWrites $written,
    ): PositionWritten {
        $item = $persist->persist($request->mediaType(), $request->mediaRef(), App::getLocale());

        $deleted = $unmark->execute($viewer, $item);

        return $written->of($viewer, $item, ['deleted' => $deleted]);
    }

    /**
     * Corrects the recorded cost of one play (games: post-game content played
     * after completing). Answers with the play and the states it moved.
     */
    public function update(
        #[CurrentUser] User $viewer,
        UpdatePlayAmountRequest $request,
        Play $play,
        UpdatePlayAmount $update,
        AffectedStates $affected,
    ): JsonResponse {
        $update->execute($viewer, $play, $request->amount());

        return response()->json([
            'data' => (new PlayResource($play->refresh()))->resolve($request),
            'states' => $affected->of($viewer, $play->mediaItem),
        ]);
    }

    /**
     * Removes one viewing from the history, not the whole position. The
     * play is gone, so the answer is the position it belonged to.
     */
    public function destroyOne(
        #[CurrentUser] User $viewer,
        Play $play,
        DeletePlay $delete,
        PositionWrites $written,
    ): PositionWritten {
        Gate::authorize('delete', $play);

        $item = $play->mediaItem;

        $delete->execute($viewer, $play);

        return $written->of($viewer, $item, ['deleted' => 1]);
    }
}

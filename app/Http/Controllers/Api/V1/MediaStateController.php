<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Library\DropAttempt;
use App\Actions\Library\LogProgress;
use App\Actions\Library\RateItem;
use App\Actions\Library\RemoveRating;
use App\Actions\Library\ResumeAttempt;
use App\Actions\Library\SetFavorite;
use App\Actions\Library\SetMediaStatus;
use App\Actions\Library\SyncListMembership;
use App\Catalog\TitleStore;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\MediaRequest;
use App\Http\Requests\Api\V1\UpdateFavoriteRequest;
use App\Http\Requests\Api\V1\UpdateListsRequest;
use App\Http\Requests\Api\V1\UpdateMediaStatusRequest;
use App\Http\Requests\Api\V1\UpdateProgressRequest;
use App\Http\Requests\Api\V1\UpdateRatingRequest;
use App\Http\Responses\PositionWrites;
use App\Http\Responses\PositionWritten;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Support\Facades\App;

/**
 * Everything a client can set on one position other than its history and
 * its bell: rating, status, progress, favorite, list membership. Thin — the
 * actions do the work; every write answers with the states it touched.
 */
class MediaStateController extends Controller
{
    public function rate(
        #[CurrentUser] User $viewer,
        UpdateRatingRequest $request,
        TitleStore $persist,
        RateItem $rate,
        PositionWrites $written,
    ): PositionWritten {
        $item = $persist->persist($request->mediaType(), $request->mediaRef(), App::getLocale());

        $rate->execute($viewer, $item, (int) $request->integer('rating'));

        return $written->of($viewer, $item);
    }

    public function removeRating(
        #[CurrentUser] User $viewer,
        MediaRequest $request,
        TitleStore $persist,
        RemoveRating $remove,
        PositionWrites $written,
    ): PositionWritten {
        $item = $persist->persist($request->mediaType(), $request->mediaRef(), App::getLocale());

        $remove->execute($viewer, $item);

        return $written->of($viewer, $item);
    }

    /**
     * Two semantics behind one field: `dropped` closes the current attempt,
     * `on_hold` pauses the open one, null clears both — on a season, the
     * show's inherited one included. Clearing resumes the attempt with the
     * sessions logged before the pause; `discard_progress` throws it away.
     */
    public function status(
        #[CurrentUser] User $viewer,
        UpdateMediaStatusRequest $request,
        TitleStore $persist,
        SetMediaStatus $setStatus,
        DropAttempt $drop,
        ResumeAttempt $resume,
        PositionWrites $written,
    ): PositionWritten {
        $item = $persist->persist($request->mediaType(), $request->mediaRef(), App::getLocale());

        $status = $request->status();

        if ($request->wantsDrop()) {
            $drop->execute($viewer, $item, $request->droppedAt(), $request->amount());
        } elseif ($status === null) {
            $resume->execute($viewer, $item, $request->loggedSessions());
        } else {
            $setStatus->execute($viewer, $item, $status);
        }

        return $written->of($viewer, $item);
    }

    public function progress(
        #[CurrentUser] User $viewer,
        UpdateProgressRequest $request,
        TitleStore $persist,
        LogProgress $log,
        PositionWrites $written,
    ): PositionWritten {
        $item = $persist->persist($request->mediaType(), $request->mediaRef(), App::getLocale());

        $log->execute($viewer, $item, $request->addAmount(), $request->setAmount());

        return $written->of($viewer, $item);
    }

    public function favorite(
        #[CurrentUser] User $viewer,
        UpdateFavoriteRequest $request,
        TitleStore $persist,
        SetFavorite $favorite,
        PositionWrites $written,
    ): PositionWritten {
        $item = $persist->persist($request->mediaType(), $request->mediaRef(), App::getLocale());

        $favorite->execute($viewer, $item, $request->boolean('favorite'));

        return $written->of($viewer, $item);
    }

    public function lists(
        #[CurrentUser] User $viewer,
        UpdateListsRequest $request,
        TitleStore $persist,
        SyncListMembership $sync,
        PositionWrites $written,
    ): PositionWritten {
        $item = $persist->persist($request->mediaType(), $request->mediaRef(), App::getLocale());

        // Ids that are not the caller's lists are ignored, not rejected —
        // the action intersects with what the user owns.
        $sync->execute($viewer, $item, $request->listIds());

        return $written->of($viewer, $item);
    }
}

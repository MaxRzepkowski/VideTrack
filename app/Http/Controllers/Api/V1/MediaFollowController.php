<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Library\SetFollow;
use App\Catalog\TitleStore;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\UpdateFollowRequest;
use App\Http\Responses\PositionWrites;
use App\Http\Responses\PositionWritten;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Support\Facades\App;

/**
 * The bell on a title: whether its releases reach the calendar and the
 * notifications regardless of the tracking matrix. The creators' bells are
 * CreatorFollowController's.
 */
class MediaFollowController extends Controller
{
    /**
     * The bell exception on a title: `follow` forces release tracking, `mute`
     * silences it, null hands the title back to the tracking matrix.
     */
    public function update(
        #[CurrentUser] User $viewer,
        UpdateFollowRequest $request,
        TitleStore $persist,
        SetFollow $setFollow,
        PositionWrites $written,
    ): PositionWritten {
        $item = $persist->persist($request->mediaType(), $request->mediaRef(), App::getLocale());

        $setFollow->execute($viewer, $item, $request->followMode());

        return $written->of($viewer, $item);
    }
}

<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Library\AddToList;
use App\Actions\Library\RemoveListItem;
use App\Catalog\TitleStore;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\MediaRequest;
use App\Http\Responses\PositionWrites;
use App\Http\Responses\PositionWritten;
use App\Library\Data\ListTarget;
use App\Models\MediaList;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Gate;

/**
 * A position on one list or off it, leaving its other lists alone. PUT
 * media/{type}/{ref}/lists sets the whole membership at once, which a client
 * that means a single list would have to read first, and two such clients
 * writing at once would lose a change.
 */
class TitleListController extends Controller
{
    // `$type` and `$ref` stand in the signatures for the route to bind the
    // list by name: the request reads them, the actions do not.

    public function add(
        #[CurrentUser] User $viewer,
        MediaRequest $request,
        string $type,
        string $ref,
        MediaList $list,
        TitleStore $persist,
        AddToList $add,
        PositionWrites $written,
    ): PositionWritten {
        Gate::authorize('update', $list);

        $item = $persist->persist($request->mediaType(), $request->mediaRef(), App::getLocale());

        $add->execute($viewer, $list, ListTarget::item($item->id));

        return $written->of($viewer, $item);
    }

    public function remove(
        #[CurrentUser] User $viewer,
        MediaRequest $request,
        string $type,
        string $ref,
        MediaList $list,
        RemoveListItem $remove,
        PositionWrites $written,
    ): PositionWritten {
        Gate::authorize('update', $list);

        // A position nobody stored is on no list: nothing to take off.
        $item = $request->storedItem() ?? abort(404);
        $entry = $list->entryOf($item);

        if ($entry !== null) {
            $remove->execute($list, $entry);
        }

        return $written->of($viewer, $item);
    }
}

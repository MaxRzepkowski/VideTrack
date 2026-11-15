<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Media\ResolveTitleDetails;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\MediaRequest;
use App\Http\Resources\Api\V1\MediaDetailsResource;
use App\Library\LibraryStates;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\App;

/**
 * One position, whatever its type — the core asks the type's provider, it
 * never knows which one that is. Reading does not persist the position:
 * only acting on it does.
 */
class MediaController extends Controller
{
    public function show(
        #[CurrentUser] User $viewer,
        MediaRequest $request,
        ResolveTitleDetails $resolve,
        LibraryStates $states,
    ): JsonResponse {
        // Same rule as the pages: a position already in the library survives
        // its provider going dark, and says so.
        $title = $resolve->execute($viewer, $request->mediaType(), $request->mediaRef(), App::getLocale());
        $details = $title->details;

        $state = $states->resolve($viewer, [$details->summary])[$details->summary->key()];

        return response()->json([
            'data' => (new MediaDetailsResource($details, $state))->resolve($request),
            'meta' => ['fromLibrary' => $title->fromLibrary],
        ]);
    }
}

<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Media\ResolveUpNext;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\ApiCollection;
use App\Http\Resources\Api\V1\UpNextResource;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The dashboard's "Up next" shelf: for every show the user is watching, the
 * first episode they have not seen, in the order they chose for the shelf.
 */
class UpNextController extends Controller
{
    public function __invoke(#[CurrentUser] User $viewer, Request $request, ResolveUpNext $upNext): JsonResponse
    {
        return (new ApiCollection($upNext->execute($viewer), UpNextResource::class))->response($request);
    }
}

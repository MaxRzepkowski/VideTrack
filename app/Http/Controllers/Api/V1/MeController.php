<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\UserResource;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;

/**
 * Who the token belongs to. The cheapest call in the API and the one every
 * client makes first to check its credentials.
 */
class MeController extends Controller
{
    public function __invoke(#[CurrentUser] User $viewer): UserResource
    {
        return new UserResource($viewer);
    }
}

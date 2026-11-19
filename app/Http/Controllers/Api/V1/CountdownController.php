<?php

namespace App\Http\Controllers\Api\V1;

use App\Catalog\MediaTypeRegistry;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\CountdownsRequest;
use App\Http\Resources\Api\V1\ApiCollection;
use App\Http\Resources\Api\V1\CountdownResource;
use App\Models\User;
use App\Tracking\Surfaces\Countdowns;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;

/**
 * Every premiere the user counts down to, soonest first and years ahead, a
 * show by its next episode only, with the events on their calendar among
 * them: the countdowns page's list, ten years ahead rather than the calendar's window.
 */
class CountdownController extends Controller
{
    public function __invoke(#[CurrentUser] User $viewer, CountdownsRequest $request, Countdowns $countdowns, MediaTypeRegistry $types): JsonResponse
    {
        $set = $countdowns->of($viewer);
        $page = $set->page($request->filters(), $request->page(), $request->perPage())
            ->withPath($request->url())->appends($request->query());

        return (new ApiCollection($page, CountdownResource::class))
            // Every kind counting down, whatever the filters kept.
            ->additional(['meta' => ['types' => $set->kinds($types)]])
            ->response($request);
    }
}

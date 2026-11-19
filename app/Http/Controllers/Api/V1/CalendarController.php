<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\CalendarRequest;
use App\Http\Resources\Api\V1\ApiCollection;
use App\Http\Resources\Api\V1\CalendarDayResource;
use App\Models\User;
use App\Tracking\Surfaces\CalendarDays;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;

/**
 * Releases in a window, from the same sources and preferences the calendar
 * page uses (the tracking matrix plus per-title bell exceptions).
 */
class CalendarController extends Controller
{
    public function __invoke(#[CurrentUser] User $viewer, CalendarRequest $request, CalendarDays $calendar): JsonResponse
    {
        $from = $request->from()->toDateString();
        $to = $request->to()->toDateString();

        // Spelled out rather than CalendarDayResource::collection(): the API
        // reference reads the day's shape and the window's meta from here.
        return (new ApiCollection($calendar->range($viewer, $from, $to), CalendarDayResource::class))
            ->additional(['meta' => ['from' => $from, 'to' => $to]])
            ->response($request);
    }
}

<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\CalendarRequest;
use App\Http\Resources\Api\V1\MediaSummaryResource;
use App\Models\User;
use App\Tracking\Data\CalendarDay;
use App\Tracking\Data\CalendarEvent;
use App\Tracking\Data\EventEntry;
use App\Tracking\Releases\ReleaseEntry;
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

        return response()->json([
            'data' => array_map(fn (CalendarDay $day): array => [
                'date' => $day->date,
                'entries' => array_map(fn (ReleaseEntry $entry): array => [
                    'showTitle' => $entry->showTitle,
                    'media' => (new MediaSummaryResource($entry->media))->resolve($request),
                ], $day->entries),
                // Showcases starting that day in the viewer's zone, as instants.
                'events' => array_map(fn (CalendarEvent $event): EventEntry => $event->event, $day->events),
            ], $calendar->range($viewer, $from, $to)),
            'meta' => ['from' => $from, 'to' => $to],
        ]);
    }
}

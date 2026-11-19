<?php

namespace App\Http\Resources\Api\V1;

use App\Tracking\Data\CalendarDay;
use App\Tracking\Data\CalendarEvent;
use App\Tracking\Data\EventEntry;
use App\Tracking\Releases\ReleaseEntry;
use Illuminate\Http\Request;

/**
 * One day of the release calendar: what comes out on it, each release as a
 * card with the show it belongs to, and the showcases starting that day in the
 * viewer's zone, as instants.
 *
 * @mixin CalendarDay
 */
class CalendarDayResource extends ApiResource
{
    public function __construct(private readonly CalendarDay $day)
    {
        parent::__construct($day);
    }

    /**
     * @return array{date: string, entries: list<array{showTitle: string|null, media: array<array-key, mixed>}>, events: list<EventEntry>}
     */
    public function toArray(Request $request): array
    {
        return [
            'date' => $this->day->date,
            'entries' => array_map(fn (ReleaseEntry $entry): array => [
                'showTitle' => $entry->showTitle,
                'media' => (new MediaSummaryResource($entry->media))->resolve($request),
            ], $this->day->entries),
            'events' => array_map(fn (CalendarEvent $event): EventEntry => $event->event, $this->day->events),
        ];
    }
}

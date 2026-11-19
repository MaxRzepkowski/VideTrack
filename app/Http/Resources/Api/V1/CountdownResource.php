<?php

namespace App\Http\Resources\Api\V1;

use App\Tracking\Data\CountdownEntry;
use App\Tracking\Data\EventCountdown;
use App\Tracking\Data\EventEntry;
use Illuminate\Http\Request;

/**
 * One countdown: a release as a card with the show it belongs to, or an event
 * on the user's calendar, as the event itself.
 */
class CountdownResource extends ApiResource
{
    public function __construct(private readonly CountdownEntry|EventCountdown $countdown)
    {
        parent::__construct($countdown);
    }

    /**
     * @return array{showTitle: string|null, media: array<array-key, mixed>}|array{event: EventEntry}
     */
    public function toArray(Request $request): array
    {
        if ($this->countdown instanceof EventCountdown) {
            return ['event' => $this->countdown->event];
        }

        return [
            'showTitle' => $this->countdown->showTitle,
            'media' => (new MediaSummaryResource($this->countdown->media))->resolve($request),
        ];
    }
}

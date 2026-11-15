<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Tracking\HideEvent;
use App\Actions\Tracking\ShowEvent;
use App\Catalog\Events\EventStore;
use App\Http\Controllers\Controller;
use App\Models\CatalogEvent;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;

/** Whether an event is on the user's calendar: every event is, until they take one off. */
class EventVisibilityController extends Controller
{
    /** Take an event (a showcase) off the calendar, the iCal feed and the "live" button */
    public function hide(#[CurrentUser] User $viewer, EventStore $events, HideEvent $hide, string $provider, string $ref): JsonResponse
    {
        $hide->execute($viewer, $this->event($events, $provider, $ref));

        return response()->json(['data' => ['hidden' => true]]);
    }

    /** Put a hidden event back on the calendar */
    public function show(#[CurrentUser] User $viewer, EventStore $events, ShowEvent $show, string $provider, string $ref): JsonResponse
    {
        $show->execute($viewer, $this->event($events, $provider, $ref));

        return response()->json(['data' => ['hidden' => false]]);
    }

    private function event(EventStore $events, string $provider, string $ref): CatalogEvent
    {
        return $events->find($provider, $ref) ?? abort(404);
    }
}

<?php

namespace App\Actions\Tracking;

use App\Models\CatalogEvent;
use App\Models\HiddenEvent;
use App\Models\User;

/** Takes an event off the user's calendar, iCal feed and "live" button. */
final readonly class HideEvent
{
    public function execute(User $user, CatalogEvent $event): void
    {
        HiddenEvent::query()->firstOrCreate(['user_id' => $user->id, 'catalog_event_id' => $event->id]);
    }
}

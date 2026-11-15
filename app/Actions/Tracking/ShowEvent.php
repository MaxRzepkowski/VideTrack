<?php

namespace App\Actions\Tracking;

use App\Models\CatalogEvent;
use App\Models\HiddenEvent;
use App\Models\User;

/** Puts a hidden event back on the user's calendar, iCal feed and "live" button. */
final readonly class ShowEvent
{
    public function execute(User $user, CatalogEvent $event): void
    {
        HiddenEvent::query()->where('user_id', $user->id)->where('catalog_event_id', $event->id)->delete();
    }
}

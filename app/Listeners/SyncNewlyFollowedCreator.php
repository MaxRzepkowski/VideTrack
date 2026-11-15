<?php

namespace App\Listeners;

use App\Catalog\Creators\CreatorKind;
use App\Catalog\Creators\CreatorTable;
use App\Enums\FollowMode;
use App\Jobs\SyncFollowedCreator;
use App\Library\Events\FollowChanged;

/**
 * A creator just followed gets its upcoming titles on the calendar within
 * seconds, not after the next morning's sweep — which stays the retry.
 */
final class SyncNewlyFollowedCreator
{
    public function handle(FollowChanged $event): void
    {
        if ($event->mode !== FollowMode::Follow || $event->creatorId === null) {
            return;
        }

        // A collection backs its parts the moment it is followed.
        if ($event->creatorKind === null || $event->creatorKind === CreatorKind::Collection) {
            return;
        }

        SyncFollowedCreator::dispatch(CreatorTable::of($event->creatorKind), $event->creatorId);
    }
}

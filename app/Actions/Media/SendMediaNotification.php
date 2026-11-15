<?php

namespace App\Actions\Media;

use App\Events\Media\MediaEventDetected;
use App\Models\User;
use App\Notifications\MediaEvent;
use App\Notifications\MediaEventPayload;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Event;

/**
 * Delivers one media event to one user, once per event key:
 * an event is about the position (user, item, event kind), never about the
 * source — a followed actor in a followed show still yields ONE ping, and a
 * catch-up re-run never doubles a day's releases.
 */
final readonly class SendMediaNotification
{
    /**
     * @return bool whether a notification was actually created
     */
    public function execute(User $user, MediaEventPayload $event): bool
    {
        if ($user->notifications()->where('dedup_key', $event->key)->exists()) {
            return false;
        }

        try {
            $user->notify(new MediaEvent($event));
        } catch (UniqueConstraintViolationException) {
            // Another run wrote the same event between the look and the write.
            return false;
        }

        // Other channels (outgoing webhooks, push) carry the same fact — they
        // subscribe here rather than re-running the detection themselves.
        Event::dispatch(new MediaEventDetected(userId: $user->id, event: $event));

        return true;
    }
}

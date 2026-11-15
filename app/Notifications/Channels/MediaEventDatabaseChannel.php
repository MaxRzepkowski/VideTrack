<?php

namespace App\Notifications\Channels;

use App\Notifications\MediaEvent;
use Illuminate\Notifications\Channels\DatabaseChannel;
use Illuminate\Notifications\Notification;

/**
 * The stock database channel, writing the event's key into a column of its
 * own as well, where the unique index keeps one per recipient.
 */
final class MediaEventDatabaseChannel extends DatabaseChannel
{
    /**
     * @return array<array-key, mixed>
     */
    protected function buildPayload($notifiable, Notification $notification): array
    {
        return [
            ...parent::buildPayload($notifiable, $notification),
            'dedup_key' => $notification instanceof MediaEvent ? $notification->event->key : null,
        ];
    }
}

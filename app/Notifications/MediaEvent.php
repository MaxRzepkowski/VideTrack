<?php

namespace App\Notifications;

use App\Models\User;
use App\Notifications\Channels\MediaEventDatabaseChannel;
use App\Notifications\Channels\UserChannels;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

/**
 * One tracked-media event (the tracking matrix): an aired
 * episode, a premiere, a moved release date, a show status change or a newly
 * announced season. The payload is structured, not prose — NotificationCopy
 * words it in the reader's language when it is read, and the event's key,
 * stored in a column of its own, makes it reach each user once.
 */
class MediaEvent extends Notification
{
    public function __construct(public readonly MediaEventPayload $event)
    {
        // Time-ordered, so notifications written in the same second read
        // back in the order they were written.
        $this->id = (string) Str::uuid7();
    }

    /**
     * @return list<class-string|string>
     */
    public function via(object $notifiable): array
    {
        // The bell, and whatever instant channels the user set up; digest
        // channels hear about it in their daily batch (SendChannelDigests).
        $channels = [MediaEventDatabaseChannel::class];

        if ($notifiable instanceof User) {
            $channels[] = UserChannels::class;
        }

        return $channels;
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        return $this->event->toStored();
    }
}

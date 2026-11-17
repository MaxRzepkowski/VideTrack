<?php

namespace App\Notifications\Channels;

use App\Jobs\DeliverChannelMessage;
use App\Models\NotificationChannel;
use App\Models\User;
use App\Notifications\MediaEvent;

/**
 * The user's instant channels, each handed the notification the moment it is
 * written. Delivery is queued per channel: a slow or dead one holds up
 * neither the others nor the run that found the release.
 */
final readonly class UserChannels
{
    public function __construct(private ChannelMessages $messages) {}

    public function send(User $notifiable, MediaEvent $notification): void
    {
        // Loaded once per user: a release run writes many notifications to
        // the same one.
        $channels = $notifiable->loadMissing('notificationChannels')->notificationChannels
            ->filter(fn (NotificationChannel $channel): bool => $channel->enabled && $channel->mode === ChannelMode::Instant);

        if ($channels->isEmpty()) {
            return;
        }

        $message = $this->messages->message($notification->event, $notifiable);

        foreach ($channels as $channel) {
            DeliverChannelMessage::dispatch($channel, $message);
        }
    }
}

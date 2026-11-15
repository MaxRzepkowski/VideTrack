<?php

namespace App\Notifications\Channels;

use App\Jobs\DeliverDiscordMessage;
use App\Models\User;
use App\Notifications\MediaEvent;
use App\Notifications\NotificationCopy;

/**
 * Instant Discord delivery: one embed per media event, queued for the user's
 * webhook the moment the notification is created. Only active when the user
 * picked the "instant" mode — the default digest mode batches instead.
 */
final readonly class DiscordWebhookChannel
{
    public function __construct(private NotificationCopy $copy) {}

    public function send(User $notifiable, MediaEvent $notification): void
    {
        $url = $notifiable->discordWebhookUrl();

        if ($url === null) {
            return;
        }

        $locale = $notifiable->preferredLocale();
        $timezone = $notifiable->preferences->effectiveTimezone();
        $event = $notification->event;

        $embed = [
            'title' => $this->copy->title($event, $locale),
            'url' => $this->copy->url($event),
            'color' => $this->copy->color($event),
            'timestamp' => now()->toIso8601String(),
        ];

        $detail = $this->copy->plainDetail($event, $locale);
        $time = $this->copy->airTime($event, $locale, $timezone);

        if ($time !== null) {
            $detail = $detail === null ? "({$time})" : "{$detail} ({$time})";
        }

        if ($detail !== null) {
            $embed['description'] = $detail;
        }

        $image = $this->copy->imageUrl($event);

        if ($image !== null) {
            $embed['thumbnail'] = ['url' => $image];
        }

        DeliverDiscordMessage::dispatch($notifiable, ['embeds' => [$embed]]);
    }
}

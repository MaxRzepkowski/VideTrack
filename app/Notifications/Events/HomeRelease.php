<?php

namespace App\Notifications\Events;

use App\Notifications\MediaEventPayload;
use App\Notifications\MediaEventType;
use App\Tracking\Releases\ReleaseEntry;

/** A film reaching homes today. */
final readonly class HomeRelease implements EventKind
{
    /**
     * Also one premiering the same day, for a viewer who follows home
     * releases and not premieres. Its key is its own, so it neither collides
     * with the premiere of the same title nor changes the keys stored for one.
     */
    public static function of(ReleaseEntry $entry, string $today): MediaEventPayload
    {
        return Released::entry(MediaEventType::HomeRelease, $entry, $today);
    }

    public function title(MediaEventPayload $event, string $locale): string
    {
        return __('notifications.now_available_outside_cinemas_title', ['title' => $event->title()], $locale);
    }

    /**
     * Nothing useful to add: the source knows the day, not the service, and
     * naming a medium would overstate the data.
     */
    public function detail(MediaEventPayload $event, string $locale): array
    {
        return [];
    }

    public function digest(MediaEventPayload $event, string $locale, string $timezone): array
    {
        return ['subject' => $event->title(), 'label' => __('common.outside_cinemas', [], $locale), 'meta' => null];
    }
}

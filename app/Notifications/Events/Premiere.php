<?php

namespace App\Notifications\Events;

use App\Notifications\Copy\CopyFormat;
use App\Notifications\MediaEventPayload;
use App\Notifications\MediaEventType;
use App\Support\Payload;
use App\Tracking\Releases\ReleaseEntry;

/** A title out today: a film's premiere, a game's or a book's, a game's DLC. */
final readonly class Premiere implements EventKind
{
    public function __construct(private CopyFormat $format) {}

    public static function of(ReleaseEntry $entry, string $today): MediaEventPayload
    {
        return Released::entry(MediaEventType::Premiere, $entry, $today);
    }

    public function title(MediaEventPayload $event, string $locale): string
    {
        return __('notifications.out_today_title', ['title' => $event->title()], $locale);
    }

    /** Where it comes out, when the release names its platforms. */
    public function detail(MediaEventPayload $event, string $locale): array
    {
        $platform = Payload::string(Payload::map($event->media['extra'] ?? null), 'platform');

        return [...$this->format->relatedFacts($event, $locale), ...($platform === null ? [] : [$platform])];
    }

    public function digest(MediaEventPayload $event, string $locale, string $timezone): array
    {
        return ['subject' => $event->title(), 'label' => __('notifications.out_today', [], $locale), 'meta' => $this->format->plain($this->detail($event, $locale))];
    }
}

<?php

namespace App\Notifications\Events;

use App\Catalog\Data\RelatedRelease;
use App\Catalog\MediaItemSummaries;
use App\Models\MediaItem;
use App\Notifications\Copy\CopyFormat;
use App\Notifications\Data\EventLine;
use App\Notifications\MediaEventPayload;
use App\Notifications\MediaEventType;

/** A tracked game's DLC, remake or remaster listed for the first time, still to come. */
final readonly class RelatedAnnounced implements EventKind
{
    public function __construct(private CopyFormat $format) {}

    public static function of(MediaItem $game, RelatedRelease $release): MediaEventPayload
    {
        return new MediaEventPayload(
            type: MediaEventType::RelatedAnnounced,
            key: "related_announced:{$game->type}:{$release->ref}",
            media: MediaItemSummaries::related($game, $release)->jsonSerialize(),
            date: $release->date,
        );
    }

    public function title(MediaEventPayload $event, string $locale): string
    {
        return __('notifications.announced_title', ['title' => $event->title()], $locale);
    }

    /** What it is and whose, then when. */
    public function detail(MediaEventPayload $event, string $locale): array
    {
        return [...$this->format->relatedFacts($event, $locale), ...$this->format->dateFact($event, $locale)];
    }

    public function line(MediaEventPayload $event, string $locale): EventLine
    {
        return new EventLine(subject: $event->title(), label: __('notifications.announced', [], $locale), facts: $this->detail($event, $locale));
    }
}

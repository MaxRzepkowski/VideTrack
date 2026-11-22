<?php

namespace App\Notifications\Events;

use App\Catalog\MediaItemSummaries;
use App\Models\MediaItem;
use App\Notifications\Copy\CopyFormat;
use App\Notifications\Data\EventLine;
use App\Notifications\MediaEventPayload;
use App\Notifications\MediaEventType;

/** A new title of a followed person or company, named after them. */
final readonly class CreatorNewTitle implements EventKind
{
    public function __construct(private CopyFormat $format) {}

    /**
     * Its key is the title's own, shared with a collection's new part:
     * whichever bell finds the title first announces it, and the others find
     * it said.
     */
    public static function of(MediaEventType $type, MediaItem $item, string $creatorName): MediaEventPayload
    {
        return new MediaEventPayload(
            type: $type,
            key: self::key($item),
            media: MediaItemSummaries::summary($item)->jsonSerialize(),
            date: $item->release_date?->toDateString(),
            personName: $type === MediaEventType::PersonNewTitle ? $creatorName : null,
            companyName: $type === MediaEventType::CompanyNewTitle ? $creatorName : null,
        );
    }

    /** The fact "this title is new", whichever bell brought it. */
    public static function key(MediaItem $item): string
    {
        return "new_title:{$item->type}:{$item->provider_ref}";
    }

    public function title(MediaEventPayload $event, string $locale): string
    {
        return $event->type === MediaEventType::CompanyNewTitle
            ? __('notifications.new_title_from_name', ['name' => $event->companyName ?? ''], $locale)
            : __('notifications.new_title_with_name', ['name' => $event->personName ?? ''], $locale);
    }

    public function detail(MediaEventPayload $event, string $locale): array
    {
        return $this->format->newTitles($event, $locale);
    }

    public function line(MediaEventPayload $event, string $locale): EventLine
    {
        return new EventLine(subject: $event->title(), label: $this->title($event, $locale), facts: $this->format->dateFact($event, $locale));
    }
}

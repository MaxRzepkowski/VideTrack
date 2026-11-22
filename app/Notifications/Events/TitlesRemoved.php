<?php

namespace App\Notifications\Events;

use App\Catalog\MediaItemSummaries;
use App\Models\MediaItem;
use App\Notifications\Copy\CopyFormat;
use App\Notifications\Data\EventLine;
use App\Notifications\MediaEventPayload;
use App\Notifications\MediaEventType;
use InvalidArgumentException;

/** Titles of the user's library their source no longer has. */
final readonly class TitlesRemoved implements EventKind
{
    public function __construct(private CopyFormat $format) {}

    /**
     * Every title of the user's library that became an archive entry in one
     * refresh, as one event.
     *
     * @param  list<MediaItem>  $items
     */
    public static function of(array $items): MediaEventPayload
    {
        if ($items === []) {
            throw new InvalidArgumentException('A removal event names at least one title.');
        }

        $summaries = array_map(fn (MediaItem $item): array => MediaItemSummaries::summary($item)->jsonSerialize(), $items);

        return new MediaEventPayload(
            type: MediaEventType::TitlesRemoved,
            key: 'titles_removed:'.md5(implode(',', array_map(fn (MediaItem $item): int => $item->id, $items))),
            media: $summaries[0],
            items: $summaries,
        );
    }

    public function title(MediaEventPayload $event, string $locale): string
    {
        return trans_choice('notifications.count_title_from_your_library_is', count($event->titles()), [], $locale);
    }

    public function detail(MediaEventPayload $event, string $locale): array
    {
        return $this->format->titlesPreview($event, $locale);
    }

    public function line(MediaEventPayload $event, string $locale): EventLine
    {
        return new EventLine(subject: $this->title($event, $locale), label: '', facts: $this->detail($event, $locale));
    }
}

<?php

namespace App\Notifications\Events;

use App\Catalog\MediaItemSummaries;
use App\Models\MediaItem;
use App\Models\MediaList;
use App\Notifications\Copy\CopyFormat;
use App\Notifications\MediaEventPayload;
use App\Notifications\MediaEventType;
use InvalidArgumentException;

/** Parts a followed collection gained. */
final readonly class CollectionParts implements EventKind
{
    public function __construct(private CopyFormat $format) {}

    /**
     * One part is a new title, keyed as a creator's is, so a re-run of the
     * weekly sync never repeats it and a director's bell that said it first
     * is not echoed. Several at once (a re-indexed saga) are one event that
     * lists them, keyed by the set.
     *
     * @param  list<MediaItem>  $parts
     */
    public static function of(MediaList $collection, array $parts): MediaEventPayload
    {
        $first = $parts[0] ?? throw new InvalidArgumentException('A collection event names at least one part.');
        $source = $collection->collectionSource() ?? throw new InvalidArgumentException('Only a provider collection gains parts.');
        $summaries = array_map(fn (MediaItem $item): array => MediaItemSummaries::summary($item)->jsonSerialize(), $parts);
        $several = count($parts) > 1;
        $ids = array_map(fn (MediaItem $item): int => $item->id, $parts);
        sort($ids);

        return new MediaEventPayload(
            type: MediaEventType::CollectionNewItem,
            key: $several
                ? "collection_items:{$collection->id}:".md5(implode(',', $ids))
                : CreatorNewTitle::key($first),
            media: $summaries[0],
            date: $several ? null : $first->release_date?->toDateString(),
            items: $several ? $summaries : null,
            collectionTitle: $collection->displayTitle(),
            collectionProvider: $source['provider'],
            collectionRef: $source['ref'],
        );
    }

    /** The series has to be named: the part's own title says nothing about which collection just grew. */
    public function title(MediaEventPayload $event, string $locale): string
    {
        $collection = ['collection' => $event->collectionTitle ?? ''];

        return $event->listsSeveral()
            ? trans_choice('notifications.count_new_parts_of_collection', count($event->titles()), $collection, $locale)
            : __('notifications.new_part_of_collection', $collection, $locale);
    }

    public function detail(MediaEventPayload $event, string $locale): array
    {
        return $this->format->newTitles($event, $locale);
    }

    public function digest(MediaEventPayload $event, string $locale, string $timezone): array
    {
        if ($event->listsSeveral()) {
            return ['subject' => $this->title($event, $locale), 'label' => '', 'meta' => $this->format->plain($this->detail($event, $locale))];
        }

        return [
            'subject' => $event->title(),
            'label' => $this->title($event, $locale),
            'meta' => $this->format->date($event->date, $event->datePrecision(), $locale),
        ];
    }
}

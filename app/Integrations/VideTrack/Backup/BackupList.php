<?php

namespace App\Integrations\VideTrack\Backup;

use App\Integrations\Import\Data\ImportListRef;
use App\Models\ListItem;
use App\Models\MediaList;

/**
 * A list with its positions in order. The watchlist travels by its system
 * key; a custom list by its title, which is what the restore matches on.
 */
final readonly class BackupList implements BackupEntry
{
    /**
     * @param  list<BackupListItem>  $items
     * @param  array<int, InvalidBackupEntry>  $unreadable  the positions of the file that could not be read, by number from 1; empty when written
     */
    public function __construct(
        public string $title,
        public ?string $description,
        public bool $isPublic,
        public ?string $systemKey,
        public array $items,
        public array $unreadable = [],
    ) {}

    /** The list with its items, and each item's title, person and company, loaded. */
    public static function of(MediaList $list): self
    {
        return new self(
            // The watchlist stores no title; the restore needs one.
            $list->displayTitle(),
            $list->description,
            $list->is_public,
            $list->system_key,
            array_values($list->items->map(fn (ListItem $row): BackupListItem => BackupListItem::of($row))->all()),
        );
    }

    public function ref(): ImportListRef
    {
        return new ImportListRef($this->title, $this->systemKey, $this->description, $this->isPublic);
    }

    public function toArray(): array
    {
        return [
            'title' => $this->title,
            'description' => $this->description,
            'is_public' => $this->isPublic,
            'system_key' => $this->systemKey,
            'items' => array_map(fn (BackupListItem $item): array => $item->toArray(), $this->items),
        ];
    }

    /**
     * Null for an entry without a title — nothing to restore it into. A
     * position that cannot be read is left out with why, the rest stay.
     *
     * @param  array<mixed>  $entry
     */
    public static function fromArray(array $entry): ?self
    {
        if (! is_string($entry['title'] ?? null)) {
            return null;
        }

        $items = [];
        $unreadable = [];

        foreach (array_values(is_array($entry['items'] ?? null) ? $entry['items'] : []) as $index => $item) {
            if (! is_array($item)) {
                continue;
            }

            try {
                $items[] = BackupListItem::fromArray($item);
            } catch (InvalidBackupEntry $e) {
                $unreadable[$index + 1] = $e;
            }
        }

        return new self(
            $entry['title'],
            is_string($entry['description'] ?? null) ? $entry['description'] : null,
            (bool) ($entry['is_public'] ?? false),
            is_string($entry['system_key'] ?? null) ? $entry['system_key'] : null,
            $items,
            $unreadable,
        );
    }
}

<?php

namespace App\Actions;

use App\Catalog\Data\MediaSummary;
use App\Catalog\MediaItemSummaries;
use App\Library\LibraryStates;
use App\Models\MediaItem;
use App\Models\User;
use Illuminate\Notifications\DatabaseNotification;

/**
 * The page of a notification that lists several titles: every title it
 * names, linked while the title still exists here, with what the viewer
 * keeps on each.
 */
final readonly class BuildNotificationPage
{
    public function __construct(private LibraryStates $states) {}

    /**
     * @return array{items: list<array{media: array<string, mixed>, available: bool}>, userStates: array<string, mixed>}
     */
    public function execute(User $viewer, DatabaseNotification $notification): array
    {
        /** @var list<array<string, mixed>> $items */
        $items = is_array($notification->data['items'] ?? null) ? $notification->data['items'] : [];

        $stored = MediaItem::query()
            ->whereIn('provider_ref', array_column($items, 'ref'))
            ->whereIn('type', array_column($items, 'type'))
            ->get()
            ->keyBy(fn (MediaItem $item): string => MediaSummary::keyOf($item->type, $item->provider_ref));

        return [
            'items' => array_map(fn (array $item): array => [
                'media' => $item,
                // A title nobody keeps any more is deleted on the next
                // refresh; its name stays here, its link does not.
                'available' => is_string($item['type'] ?? null)
                    && is_string($item['ref'] ?? null)
                    && $stored->has("{$item['type']}:{$item['ref']}"),
            ], $items),
            'userStates' => $this->states->resolve($viewer, $stored->map(fn (MediaItem $item) => MediaItemSummaries::summary($item))->values()),
        ];
    }
}

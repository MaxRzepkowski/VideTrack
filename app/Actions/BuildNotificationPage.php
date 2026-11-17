<?php

namespace App\Actions;

use App\Catalog\Data\MediaSummary;
use App\Catalog\MediaItemSummaries;
use App\Library\LibraryStates;
use App\Models\MediaItem;
use App\Models\User;
use App\Support\Payload;
use Illuminate\Notifications\DatabaseNotification;

/**
 * The page of a notification that lists several titles: every title it
 * names, linked while the title still exists here, with what the viewer
 * keeps on each. A title still stored shows its card as the history does
 * (an episode under its show's poster and name); one deleted since is only
 * counted.
 */
final readonly class BuildNotificationPage
{
    public function __construct(
        private LibraryStates $states,
        private MediaItemSummaries $summaries,
    ) {}

    /**
     * @return array{items: list<array{media: MediaSummary|null, available: bool}>, userStates: array<string, mixed>}
     */
    public function execute(User $viewer, DatabaseNotification $notification): array
    {
        $items = Payload::list($notification->data['items'] ?? null);

        $cards = MediaItem::query()
            ->with('parent.parent')
            ->whereIn('provider_ref', array_column($items, 'ref'))
            ->whereIn('type', array_column($items, 'type'))
            ->get()
            ->mapWithKeys(fn (MediaItem $item): array => [MediaSummary::keyOf($item->type, $item->provider_ref) => $this->summaries->inContext($item)]);

        return [
            'items' => array_map(function (array $item) use ($cards): array {
                $type = Payload::string($item, 'type');
                $ref = Payload::string($item, 'ref');
                $card = $type === null || $ref === null ? null : $cards->get(MediaSummary::keyOf($type, $ref));

                return ['media' => $card, 'available' => $card !== null];
            }, $items),
            'userStates' => $this->states->resolve($viewer, $cards->values()),
        ];
    }
}

<?php

namespace App\Actions\Media;

use App\Accounts\Preferences\NotificationEvent;
use App\Enums\FollowMode;
use App\Library\Data\CollectionSyncResult;
use App\Models\Follow;
use App\Models\MediaItem;
use App\Notifications\MediaEventPayload;
use App\Tracking\UpcomingTitleWindow;

/**
 * "A new part of a series you follow" — the one engine behind film sagas,
 * book series and game series, because all three are provider collections.
 *
 * The criterion is explicit and narrow: the bell on the collection page, in
 * follow mode. Neither the tracking matrix nor a guess from watched parts
 * qualifies — a saga browsed once out of curiosity must stay silent.
 *
 * The event fires when the provider ADDS the part, not on its release day:
 * the release itself is covered for anyone whose countdowns the part enters.
 * Only a part still to come is news, by the window a followed creator's
 * titles answer to: one filed under the saga long after it came out is the
 * provider tidying its catalogue, and one without a date yet is news once
 * it gets one, as the new date of a part the saga's bell follows.
 */
final readonly class NotifyCollectionAdditions
{
    /**
     * Up to this many new parts, each gets its own notification; more (a
     * re-indexed saga) arrive as one, listing them all on its page.
     */
    private const SEPARATE_UP_TO = 5;

    public function __construct(
        private SendMediaNotification $send,
        private UpcomingTitleWindow $window,
    ) {}

    /**
     * @return int notifications actually sent
     */
    public function execute(CollectionSyncResult $result): int
    {
        // Materialization is not a change: every part of a freshly opened
        // collection counts as "added", and announcing twenty of them would
        // be the loudest possible bug.
        if ($result->wasCreated || $result->addedItemIds === []) {
            return 0;
        }

        $followers = Follow::query()
            ->where('list_id', $result->list->id)
            ->where('mode', FollowMode::Follow->value)
            ->with('user')
            ->get()
            ->filter(fn (Follow $follow): bool => $follow->user->preferences->notifies(NotificationEvent::CollectionItems));

        if ($followers->isEmpty()) {
            return 0;
        }

        $items = MediaItem::query()->whereIn('id', $result->addedItemIds)->get()
            ->filter(fn (MediaItem $item): bool => $this->window->admitsStored($item))
            ->sortBy(fn (MediaItem $item): int|false => array_search($item->id, $result->addedItemIds, true))
            ->values();

        if ($items->isEmpty()) {
            return 0;
        }

        $payloads = $items->count() <= self::SEPARATE_UP_TO
            ? $items->map(fn (MediaItem $item): MediaEventPayload => MediaEventPayload::collectionParts($result->list, [$item]))->all()
            : [MediaEventPayload::collectionParts($result->list, array_values($items->all()))];

        $sent = 0;

        foreach ($followers as $follow) {
            foreach ($payloads as $payload) {
                $sent += (int) $this->send->execute($follow->user, $payload);
            }
        }

        return $sent;
    }
}

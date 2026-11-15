<?php

namespace App\Actions\Media;

use App\Actions\Library\BackCreatorTitles;
use App\Catalog\Creators\CreatorKind;
use App\Catalog\Data\CollectionDetails;
use App\Library\CollectionSponsorship;

/**
 * Brings a collection's list up to what its provider says now, and lets its
 * followers know what it gained: the new parts inherit the saga's bell and
 * are announced. The weekly task and a manual refresh alike — a refresh that
 * only synced would swallow the event for every follower.
 */
final readonly class RefreshCollection
{
    public function __construct(
        private SyncCollectionList $sync,
        private CollectionSponsorship $sponsorship,
        private NotifyCollectionAdditions $notify,
        private BackCreatorTitles $back,
    ) {}

    /**
     * @return int notifications sent
     */
    public function execute(string $provider, CollectionDetails $collection, string $locale): int
    {
        $result = $this->sync->execute($provider, $collection, $locale);

        // The new parts inherit the saga's bell for everyone who follows it,
        // so they reach the calendar, the countdowns and the release
        // notifications like the rest.
        $followers = $this->sponsorship->owedNewParts($result);
        $parts = $followers->isEmpty() ? [] : $this->sponsorship->upcomingParts($result->list);

        foreach ($followers as $follower) {
            $this->back->execute($follower, CreatorKind::Collection, $result->list->id, $parts);
        }

        return $this->notify->execute($result);
    }
}

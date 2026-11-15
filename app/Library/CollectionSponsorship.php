<?php

namespace App\Library;

use App\Catalog\Creators\CreatorKind;
use App\Enums\FollowMode;
use App\Library\Data\CollectionSyncResult;
use App\Models\Follow;
use App\Models\MediaItem;
use App\Models\MediaList;
use App\Models\User;
use App\Support\Numbers;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * What a followed collection backs: its parts still to come. A bell is a
 * promise about the future — a saga released decades ago would otherwise dump
 * dozens of rows on the followed page the moment it is followed. A part
 * without a date counts as upcoming: an announced sequel looks exactly like
 * that. Parts released later keep their bell as history, which carries them
 * into the "past releases" tab instead of vanishing.
 */
final readonly class CollectionSponsorship
{
    public function __construct(private Sponsorships $sponsorships) {}

    /**
     * Who follows the saga — to whom parts a refresh found new owe a bell. A
     * list just materialized, or a refresh that added nothing, owes nobody.
     *
     * @return Collection<int, User>
     */
    public function owedNewParts(CollectionSyncResult $result): Collection
    {
        if ($result->wasCreated || $result->addedItemIds === []) {
            return new Collection;
        }

        return Follow::query()
            ->where('list_id', $result->list->id)
            ->where('mode', FollowMode::Follow->value)
            ->with('user')
            ->get()
            ->map(fn (Follow $follow): User => $follow->user)
            ->values()
            ->toBase();
    }

    /**
     * The parts the saga's bell covers: those still to come. Also repairs
     * parts that arrived while nothing was watching.
     *
     * @return list<int>
     */
    public function upcomingParts(MediaList $collection): array
    {
        return Numbers::ints(MediaItem::query()
            ->whereIn('id', $collection->items()->whereNotNull('media_item_id')->select('media_item_id'))
            ->where(fn (Builder $query) => $query->whereNull('release_date')->orWhere('release_date', '>', today()))
            ->pluck('id'));
    }

    /**
     * @return list<int> the parts that got a row of their own
     */
    public function sponsorUpcomingParts(User $user, MediaList $collection): array
    {
        return $this->sponsorships->sponsor($user, CreatorKind::Collection, $collection->id, $this->upcomingParts($collection));
    }
}

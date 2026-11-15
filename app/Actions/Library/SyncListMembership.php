<?php

namespace App\Actions\Library;

use App\Library\Data\ListTarget;
use App\Library\ListMembership;
use App\Models\MediaItem;
use App\Models\User;
use App\Support\Numbers;

/** Sets which of the user's lists — the watchlist included — hold a title. */
final readonly class SyncListMembership
{
    public function __construct(private ListMembership $membership) {}

    /**
     * @param  list<int>  $listIds
     */
    public function execute(User $user, MediaItem $item, array $listIds): void
    {
        $own = collect(Numbers::ints($user->lists()->pluck('id')));

        $this->membership->set($user, $own, ListTarget::item($item->id), $listIds);
    }
}

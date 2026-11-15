<?php

namespace App\Actions\Library;

use App\Library\Data\ListTarget;
use App\Library\ListMembership;
use App\Models\MediaList;
use App\Models\User;
use Carbon\CarbonImmutable;

/** One position onto the end of one of the user's lists, unless it is there already. */
final readonly class AddToList
{
    public function __construct(private ListMembership $membership) {}

    /**
     * @param  CarbonImmutable|null  $addedAt  when it went on, where the history says so (an import); now otherwise
     * @return bool whether it was added
     */
    public function execute(User $user, MediaList $list, ListTarget $target, ?CarbonImmutable $addedAt = null): bool
    {
        return $this->membership->add($user, $list, $target, $addedAt);
    }
}

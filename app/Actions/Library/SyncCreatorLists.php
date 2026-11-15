<?php

namespace App\Actions\Library;

use App\Actions\Media\PersistCreator;
use App\Catalog\Creators\CreatorKind;
use App\Catalog\Creators\CreatorRef;
use App\Library\Data\ListTarget;
use App\Library\ListMembership;
use App\Models\User;
use App\Support\Numbers;
use Illuminate\Support\Collection;
use LogicException;

/**
 * Sets which of the user's custom lists hold a person or a company — never
 * the watchlist, which means "intend to consume". A creator gets its row on
 * the way, the first time it goes on a list.
 */
final readonly class SyncCreatorLists
{
    public function __construct(
        private PersistCreator $persist,
        private ListMembership $membership,
    ) {}

    /**
     * @param  list<int>  $listIds
     */
    public function execute(User $user, CreatorRef $creator, array $listIds, string $locale): void
    {
        // Asked before the provider is: a collection is no position on a list.
        if ($creator->kind === CreatorKind::Collection) {
            throw new LogicException('A collection goes on no list.');
        }

        $row = $this->persist->execute($creator, $locale);

        $own = collect(Numbers::ints($user->lists()->whereNull('system_key')->pluck('id')));

        $this->membership->set($user, $own, ListTarget::creator($creator->kind, $row->id), $listIds);
    }
}

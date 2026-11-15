<?php

namespace App\Library;

use App\Library\Data\ListTarget;
use App\Library\Events\ListItemAdded;
use App\Library\Events\ListItemRemoved;
use App\Models\ListItem;
use App\Models\MediaList;
use App\Models\User;
use App\Support\Numbers;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

/**
 * Which of the user's lists hold something — a title, a person, a company.
 * The lists modal sends the whole desired set; this takes the difference:
 * new entries go to the end of each list, and each change is announced.
 */
final readonly class ListMembership
{
    public function __construct(
        private MutationContext $mutations,
        private ListPositions $positions,
    ) {}

    /**
     * @param  Collection<int, int>  $ownListIds  the lists the target may sit on
     * @param  list<int>  $listIds
     */
    public function set(User $user, Collection $ownListIds, ListTarget $target, array $listIds): void
    {
        DB::transaction(function () use ($user, $ownListIds, $target, $listIds): void {
            $desired = $ownListIds->intersect($listIds);

            $current = collect(Numbers::ints(ListItem::query()
                ->whereIn('list_id', $ownListIds)
                ->where($target->attributes())
                ->pluck('list_id')));

            $removed = $current->diff($desired);

            ListItem::query()
                ->whereIn('list_id', $removed)
                ->where($target->attributes())
                ->delete();

            foreach ($removed as $listId) {
                Event::dispatch(new ListItemRemoved(
                    userId: $user->id,
                    source: $this->mutations->current(),
                    listId: $listId,
                    target: $target,
                ));
            }

            foreach ($desired->diff($current) as $listId) {
                $this->append($user, $listId, $target, null);
            }
        });
    }

    /**
     * Puts one position at the end of one of the user's lists unless it is
     * there already.
     *
     * @param  CarbonImmutable|null  $addedAt  when it went on, where the history says so (an import); now otherwise
     * @return bool whether it was added
     */
    public function add(User $user, MediaList $list, ListTarget $target, ?CarbonImmutable $addedAt = null): bool
    {
        if (ListItem::query()->where('list_id', $list->id)->where($target->attributes())->exists()) {
            return false;
        }

        try {
            $this->append($user, $list->id, $target, $addedAt);
        } catch (UniqueConstraintViolationException) {
            // Added by another request between the check and the insert: it is on the list.
            return false;
        }

        return true;
    }

    private function append(User $user, int $listId, ListTarget $target, ?CarbonImmutable $addedAt): void
    {
        $this->positions->append($listId, [
            ...$target->attributes(),
            ...($addedAt === null ? [] : ['created_at' => $addedAt, 'updated_at' => $addedAt]),
        ]);

        Event::dispatch(new ListItemAdded(
            userId: $user->id,
            source: $this->mutations->current(),
            listId: $listId,
            target: $target,
        ));
    }
}

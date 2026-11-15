<?php

namespace App\Library;

use App\Models\MediaItem;
use App\Models\User;
use App\Models\WatchCycle;
use Illuminate\Support\Collection;

final readonly class WatchCycleResolver
{
    /** How plays group by cycle: null is the implicit first one. */
    public static function key(?int $cycleId): string
    {
        return $cycleId === null ? 'base' : "cycle:{$cycleId}";
    }

    /**
     * The latest explicit cycle for a root. Null represents the implicit
     * initial cycle.
     */
    public function activeFor(User $user, MediaItem $root): ?WatchCycle
    {
        return WatchCycle::query()
            ->where('user_id', $user->id)
            ->where('media_item_id', $root->id)
            ->orderByDesc('number')
            ->first();
    }

    /**
     * Resolve active cycles for several roots with one query. Every supplied
     * root gets an entry; null means its implicit initial cycle is active.
     *
     * @param  Collection<int, MediaItem>  $roots
     * @return array<int, WatchCycle|null>
     */
    public function activeForRoots(User $user, Collection $roots): array
    {
        $active = array_fill_keys($roots->map(fn (MediaItem $root): int => $root->id)->all(), null);

        if ($active === []) {
            return [];
        }

        foreach (WatchCycle::query()
            ->where('user_id', $user->id)
            ->whereIn('media_item_id', array_keys($active))
            ->orderByDesc('number')
            ->get() as $cycle) {
            $active[$cycle->media_item_id] ??= $cycle;
        }

        return $active;
    }
}

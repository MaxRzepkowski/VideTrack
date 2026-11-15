<?php

namespace App\Library;

use App\Catalog\Data\SubtreeNode;
use App\Catalog\Hierarchy;
use App\Enums\PlayOutcome;
use App\Models\MediaItem;
use App\Models\Play;
use App\Models\User;
use App\Support\Numbers;
use App\Support\QueryRow;
use Illuminate\Support\Collection;
use stdClass;

/**
 * The attempt model's questions, asked by every way of writing the library:
 * which entry closed the latest attempt at a position in a run, and when a
 * container counts as fully watched.
 */
final readonly class Attempts
{
    public function __construct(private Hierarchy $hierarchy) {}

    /** The newest entry of the position in the run; a tie of moments goes to the later write. */
    public function latest(User $user, MediaItem $item, ?int $cycleId): ?Play
    {
        return Play::query()
            ->where('user_id', $user->id)
            ->where('media_item_id', $item->id)
            ->inCycle($cycleId)
            ->orderByDesc('watched_at')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * What one run settled of these leaves, read from the history: their own
     * entries, and their seasons' drops.
     *
     * @param  array<int, int|null>  $parents  leaf id => its parent's id ({@see parents()})
     */
    public function settlement(User $user, array $parents, ?int $cycleId): Settlement
    {
        $completions = Play::query()
            ->where('user_id', $user->id)
            ->whereIn('media_item_id', array_keys($parents))
            ->inCycle($cycleId)
            ->toBase()
            ->groupBy('media_item_id')
            ->selectRaw('media_item_id, SUM(CASE WHEN outcome = ? THEN 1 ELSE 0 END) AS completions', [PlayOutcome::Completed->value])
            ->get()
            ->mapWithKeys(fn (stdClass $row): array => [QueryRow::int($row, 'media_item_id') => QueryRow::int($row, 'completions')]);

        $drops = Numbers::ints(Play::query()
            ->where('user_id', $user->id)
            ->where('outcome', PlayOutcome::Dropped->value)
            ->whereIn('media_item_id', array_values(array_unique(array_filter($parents))))
            ->inCycle($cycleId)
            ->distinct()
            ->pluck('media_item_id'));

        return new Settlement($completions->all(), array_fill_keys($drops, true));
    }

    /**
     * @param  Collection<int, MediaItem>  $leaves
     * @return array<int, int|null> leaf id => its parent's id
     */
    public static function parents(Collection $leaves): array
    {
        return $leaves->mapWithKeys(fn (MediaItem $leaf): array => [$leaf->id => $leaf->parent_id])->all();
    }

    /**
     * Every aired playable descendant has a completed play — the threshold at
     * which an intermediate container's drop stops holding.
     */
    public function fullyWatched(User $user, MediaItem $container, ?int $cycleId): bool
    {
        $aired = $this->hierarchy->playableNodes($container)
            ->filter(fn (SubtreeNode $leaf): bool => $leaf->aired)
            ->pluck('id');

        if ($aired->isEmpty()) {
            return false;
        }

        $completed = Play::query()
            ->where('user_id', $user->id)
            ->where('outcome', PlayOutcome::Completed->value)
            ->whereIn('media_item_id', $aired)
            ->inCycle($cycleId)
            ->distinct()
            ->count('media_item_id');

        return $completed === $aired->count();
    }
}

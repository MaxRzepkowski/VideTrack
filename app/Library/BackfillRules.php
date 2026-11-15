<?php

namespace App\Library;

use App\Catalog\Hierarchy;
use App\Catalog\MediaTypeRegistry;
use App\Enums\PlayOutcome;
use App\Models\MediaItem;
use App\Models\Play;
use App\Models\User;
use App\Models\WatchCycle;
use App\Support\Numbers;
use Carbon\CarbonImmutable;

/**
 * What a dated fact from elsewhere (an import, a restored backup) needs to
 * find its place in the library: the run it belongs to, whether something
 * newer already stands against it, the counter it may keep. The Backfill*
 * actions share it; a click asks none of this, it happens now.
 */
final readonly class BackfillRules
{
    public function __construct(
        private MediaTypeRegistry $types,
        private Hierarchy $hierarchy,
    ) {}

    /**
     * The run of the item's root that was under way on that day: the latest
     * one started by then, else the implicit first. Only shows and other
     * container roots are watched again; anything else has one run.
     */
    public function cycleAt(User $user, MediaItem $root, CarbonImmutable $at): ?int
    {
        $definition = $this->types->get($root->type);

        if (! $definition->isRoot() || $definition->childType === null) {
            return null;
        }

        return Numbers::int(WatchCycle::query()
            ->where('user_id', $user->id)
            ->where('media_item_id', $root->id)
            ->where('started_at', '<=', $at)
            ->orderByDesc('number')
            ->value('id'));
    }

    /**
     * The run of the root the source numbers explicitly (2 and up), made the
     * first time it is met: by its own entry, which knows when it began, or
     * else by its first viewing. A run the library holds already keeps its
     * start — a backup writes the runs before the viewings, so a viewing
     * from before the run's start (one logged with an earlier date) does not
     * move it. Null for a title that is watched only once.
     */
    public function numberedCycle(User $user, MediaItem $root, int $number, CarbonImmutable $startedAt): ?WatchCycle
    {
        $definition = $this->types->get($root->type);

        if (! $definition->isRoot() || $definition->childType === null || $number < 2) {
            return null;
        }

        return WatchCycle::query()->firstOrCreate(
            ['user_id' => $user->id, 'media_item_id' => $root->id, 'number' => $number],
            ['started_at' => $startedAt],
        );
    }

    /**
     * A completed viewing dated after the moment, in the run: of the item
     * itself, or — for a container — of a part under it the library held
     * before the batch. A file telling a film finished after it was dropped
     * means the user went on; one telling a show's episodes and that the show
     * is dropped says what the show is now. A state set at the very moment
     * of a viewing is the later of the two and stands.
     */
    public function completedAfter(User $user, MediaItem $item, ?int $cycleId, CarbonImmutable $at, BackfillBatch $batch): bool
    {
        $plays = Play::query()
            ->where('user_id', $user->id)
            ->where('outcome', PlayOutcome::Completed->value)
            ->where('watched_at', '>', $at)
            ->inCycle($cycleId);

        return ($this->types->get($item->type)->playable
            ? $plays->where('media_item_id', $item->id)
            : $batch->heldBefore($plays->whereIn('media_item_id', $this->hierarchy->cascadeIds($item)->all()))
        )->exists();
    }

    /**
     * A counter carried from the source, kept for types with a progress unit
     * and only when positive — a show's episode tally or a film's 0/1 flag
     * must never land in amounts. Kept verbatim: the user's own edition may
     * exceed the locally cached maximum, which only a click is clamped to.
     */
    public function amount(MediaItem $item, ?int $amount): ?int
    {
        if ($amount === null || $amount <= 0) {
            return null;
        }

        return $this->types->get($item->type)->progressUnit === null ? null : $amount;
    }

    /**
     * Created/updated stamps for a row dated at the source; empty when the
     * source has no date, letting the row be stamped now.
     *
     * @return array<string, CarbonImmutable>
     */
    public function timestamps(?CarbonImmutable $at): array
    {
        return $at === null ? [] : ['created_at' => $at, 'updated_at' => $at];
    }
}

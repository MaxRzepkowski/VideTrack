<?php

namespace App\Library\Data;

use App\Catalog\Data\CascadeLeaf;
use App\Library\Settlement;
use App\Models\MediaItem;
use App\Models\MediaUserState;

/**
 * What the history of one user holds about a set of positions: the positions
 * and their leaves, the plays per viewing cycle, the drops on containers and
 * the open attempts.
 */
final readonly class HistoryFacts
{
    /**
     * @param  array<int, MediaItem>  $items  the positions asked about, by id
     * @param  array<int, list<CascadeLeaf>>  $leaves  container id => its cascade leaves in hierarchy order, for every container asked for and every root reached through one
     * @param  array<int, int>  $rootOf  position, leaf or leaf parent id => id of its hierarchy root
     * @param  array<int, MediaItem>  $roots  root containers by id, asked for or reached through a season
     * @param  array<int, int>  $cycles  root id => number of the viewing cycle it is in; absent = the implicit first
     * @param  array<int, PlayTally>  $history  item id => its plays across every cycle
     * @param  array<int, PlayTally>  $current  item id => its plays in the cycle its root is in now
     * @param  array<string, array<int, PlayTally>>  $byCycle  cycle key => item id => its plays in that cycle
     * @param  array<int, string>  $drops  container id => when it was dropped in the current cycle
     * @param  array<string, array<int, true>>  $dropsByCycle  cycle key => ids of the containers dropped in it
     * @param  array<int, MediaUserState>  $openStates  item id => its open attempt, the positions' roots included
     */
    public function __construct(
        public array $items,
        public array $leaves,
        public array $rootOf,
        public array $roots,
        public array $cycles,
        public array $history,
        public array $current,
        public array $byCycle,
        public array $drops,
        public array $dropsByCycle,
        public array $openStates,
    ) {}

    public function rootIdOf(MediaItem $item): int
    {
        return $this->rootOf[$item->id] ?? $item->id;
    }

    public function cycleOf(MediaItem $item): int
    {
        return $this->cycles[$this->rootIdOf($item)] ?? 1;
    }

    /** What the cycle each root is in now has settled. */
    public function settlement(): Settlement
    {
        return new Settlement(array_map(fn (PlayTally $tally): int => $tally->completions, $this->current), $this->drops);
    }

    /** What one cycle settled, by its key. */
    public function settlementIn(string $cycle): Settlement
    {
        return new Settlement(
            array_map(fn (PlayTally $tally): int => $tally->completions, $this->byCycle[$cycle] ?? []),
            $this->dropsByCycle[$cycle] ?? [],
        );
    }
}

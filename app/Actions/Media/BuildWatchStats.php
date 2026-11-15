<?php

namespace App\Actions\Media;

use App\Catalog\MediaTypeDefinition;
use App\Catalog\MediaTypeRegistry;
use App\Enums\PlayOutcome;
use App\Library\Data\WatchStats;
use App\Library\PlayCost;
use App\Models\MediaUserState;
use App\Models\Play;
use App\Models\User;
use App\Support\Numbers;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Collection;

/**
 * Watch-time statistics for the dashboard tiles, keyed by
 * ROOT type code ('show', 'movie'): watch time = plays × runtime, unique
 * watched items, total plays and the number of distinct root titles. An item
 * whose own runtime is missing is priced at the one its hierarchy declares —
 * the same rule the progress bars quote, so the tile and the bar never tell
 * two stories about the same hours.
 */
final readonly class BuildWatchStats
{
    public function __construct(
        private MediaTypeRegistry $types,
        private PlayCost $cost,
    ) {}

    /**
     * @return array<string, WatchStats>
     */
    public function execute(User $user): array
    {
        $stats = [];

        foreach ($this->types->all() as $definition) {
            if ($definition->isRoot() && $definition->searchable) {
                $stats[$definition->code] = ['minutes' => 0, 'watched' => 0, 'plays' => 0, 'titles' => 0];
            }
        }

        // Counting rules: movies and episodes
        // mean COMPLETED attempts — a dropped movie is not a watched movie.
        // Progress types (games, books) count every ATTEMPT instead: the
        // title tally includes dropped and open (in progress / on hold)
        // attempts, the play tally every history entry (completed +
        // dropped). Time is what each entry cost (PlayCost); the title an
        // entry is priced against is the one the library projection files
        // the position under, so the core needs no knowledge of how deep a
        // hierarchy goes.
        [$cost, $costBindings] = $this->cost->sql();

        $completed = PlayOutcome::Completed->value;
        $rows = Play::query()
            ->where('plays.user_id', $user->id)
            ->join('media_items', 'media_items.id', '=', 'plays.media_item_id')
            ->leftJoin('library_entries', fn (JoinClause $join) => $join
                ->on('library_entries.media_item_id', '=', 'plays.media_item_id')
                ->on('library_entries.user_id', '=', 'plays.user_id'))
            ->leftJoin('media_items as roots', 'roots.id', '=', 'library_entries.root_id')
            ->groupBy('media_items.id', 'media_items.type', 'media_items.runtime', 'library_entries.root_id')
            ->selectRaw('media_items.id as item_id, media_items.type as type, media_items.runtime as runtime, library_entries.root_id as root_id')
            ->selectRaw('sum(case when plays.outcome = ? then 1 else 0 end) as play_count', [$completed])
            ->selectRaw('count(*) as attempt_count')
            ->selectRaw("sum({$cost}) as minutes", $costBindings)
            ->toBase()
            ->get();

        // A type whose module the instance switched off has no tile to count into.
        $served = fn (Collection $group, int|string $type): bool => $this->types->has((string) $type);

        foreach ($rows->groupBy('type')->filter($served) as $type => $group) {
            $definition = $this->types->get((string) $type);
            $code = $this->rootCode($definition);
            $attemptCounting = $definition->progressUnit !== null;

            $countedRows = $attemptCounting
                ? $group
                : $group->filter(fn (object $row) => (Numbers::int($row->play_count) ?? 0) > 0);

            $stats[$code] ??= ['minutes' => 0, 'watched' => 0, 'plays' => 0, 'titles' => 0];
            $stats[$code]['minutes'] += Numbers::int($group->sum('minutes')) ?? 0;
            $stats[$code]['watched'] += $countedRows->count();
            $stats[$code]['plays'] += Numbers::int($group->sum($attemptCounting ? 'attempt_count' : 'play_count')) ?? 0;
            $stats[$code]['titles'] += $countedRows->pluck('root_id')->unique()->count();
        }

        // Open attempts of progress types (reading / playing / paused): the
        // session counter feeds the amount tally, and a title without any
        // history entry yet still counts as a title.
        $openStates = MediaUserState::query()
            ->where('media_user_states.user_id', $user->id)
            ->join('media_items', 'media_items.id', '=', 'media_user_states.media_item_id')
            ->selectRaw('media_items.id as item_id, media_items.type as type, coalesce(media_user_states.progress, 0) as minutes')
            ->toBase()
            ->get();

        $playedItemIds = $rows->pluck('item_id')->flip();

        foreach ($openStates->groupBy('type')->filter($served) as $type => $group) {
            $definition = $this->types->get((string) $type);
            $code = $this->rootCode($definition);

            $stats[$code] ??= ['minutes' => 0, 'watched' => 0, 'plays' => 0, 'titles' => 0];
            $stats[$code]['minutes'] += Numbers::int($group->sum('minutes')) ?? 0;

            if ($definition->progressUnit !== null) {
                $fresh = $group->filter(fn (object $row) => ! $playedItemIds->has($row->item_id))->count();

                $stats[$code]['watched'] += $fresh;
                $stats[$code]['titles'] += $fresh;
            }
        }

        return array_map(fn (array $tile): WatchStats => new WatchStats(...$tile), $stats);
    }

    private function rootCode(MediaTypeDefinition $definition): string
    {
        $lineage = $this->types->lineage($definition->code);

        return $lineage[array_key_last($lineage)]->code;
    }
}

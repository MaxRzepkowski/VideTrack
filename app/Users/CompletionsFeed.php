<?php

namespace App\Users;

use App\Accounts\UserCalendar;
use App\Catalog\MediaTypeRegistry;
use App\Enums\PlayOutcome;
use App\Models\LibraryEntry;
use App\Models\Play;
use App\Models\User;
use App\Support\PageInfo;
use App\Support\QueryRow;
use App\Users\Data\ActivityFilters;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * The history panel sorted by times finished: one card per position, counted
 * by the library projection. Without a type the positions are the ones a
 * play is logged on — films, episodes, games — so an episode watched again
 * shows as itself; picking a show or a season lists those, counted by their
 * full runs.
 *
 * The date range picks the positions, it does not cut the count: one is
 * listed when it was finished, or had something below it finished, inside
 * the range, and still shows every time it was finished.
 */
final readonly class CompletionsFeed
{
    /** The ids a finish counts for: the finished position and the two above it (show → season → episode). */
    private const COUNTS_FOR = ['media_items.id', 'parents.id', 'grandparents.id'];

    public function __construct(
        private MediaTypeRegistry $types,
        private TitleFilters $titles,
        private ViewerDays $days,
    ) {}

    /**
     * Each position comes with when it was last finished — for a show or a
     * season, when one of its parts last was: no run's own finish is kept.
     *
     * @return array{entries: list<array{entry: LibraryEntry, at: CarbonImmutable|null}>, pagination: PageInfo}
     */
    public function page(User $user, ActivityFilters $filters, int $page, UserCalendar $calendar): array
    {
        $playable = $filters->type === null || $this->types->get($filters->type)->playable;

        $query = LibraryEntry::query()
            ->everFinished()
            ->where('library_entries.user_id', $user->id)
            ->join('media_items', 'media_items.id', '=', 'library_entries.media_item_id')
            ->select('library_entries.*');

        if ($filters->type === null) {
            $query->whereIn('media_items.type', $this->types->playableCodes());
        } else {
            $query->where('media_items.type', $filters->type);
        }

        // A playable's own entry knows its last completion; a container's
        // knows only its last play of any outcome, so its last finish below
        // is read from the plays.
        if ($playable) {
            $query->selectRaw('library_entries.watched_at as last_finished_at');
        } else {
            $query
                ->leftJoinSub($this->lastFinishes($user), 'last_finishes', 'last_finishes.position_id', '=', 'media_items.id')
                ->addSelect('last_finishes.last_finished_at');
        }

        if ($filters->from !== null || $filters->to !== null) {
            $finished = $this->finishes($user);
            $this->days->apply($finished, 'plays.watched_at', $filters, $calendar);

            $query->where(function (Builder $sub) use ($finished): void {
                foreach (self::COUNTS_FOR as $column) {
                    $sub->orWhereIn('media_items.id', (clone $finished)->select($column));
                }
            });
        }

        $this->titles->apply($query, $user, $filters, 'media_items.id', []);

        $paginator = $query
            ->orderBy('library_entries.completed_cycles', $filters->dir->value)
            ->orderByDesc('last_finished_at')
            ->orderByRaw('media_items.title collate nocase asc')
            ->orderBy('library_entries.id')
            ->paginate(ActivityFeed::PER_PAGE, page: $page);

        /** @var Collection<int, LibraryEntry> $entries */
        $entries = $paginator->getCollection();
        $entries->load('mediaItem.parent.parent');

        return [
            'entries' => array_values(array_map(function (LibraryEntry $entry): array {
                $at = QueryRow::textOrNull($entry, 'last_finished_at');

                return ['entry' => $entry, 'at' => $at === null ? null : CarbonImmutable::parse($at)];
            }, $entries->all())),
            'pagination' => new PageInfo($paginator->currentPage(), max(1, $paginator->lastPage()), $paginator->total()),
        ];
    }

    /**
     * The user's completed plays, each joined to the position it finished
     * and the two above it.
     *
     * @return Builder<Play>
     */
    private function finishes(User $user): Builder
    {
        $finishes = Play::query()
            ->where('plays.user_id', $user->id)
            ->where('plays.outcome', PlayOutcome::Completed->value)
            ->join('media_items', 'media_items.id', '=', 'plays.media_item_id');

        $this->titles->joinAncestors($finishes);

        return $finishes;
    }

    /** Per position: the last time it, or anything below it, was finished. */
    private function lastFinishes(User $user): QueryBuilder
    {
        $levels = array_map(
            fn (string $column): QueryBuilder => $this->finishes($user)->toBase()->select(["{$column} as position_id", 'plays.watched_at as finished_at']),
            self::COUNTS_FOR,
        );

        $union = array_shift($levels);

        foreach ($levels as $level) {
            $union->unionAll($level);
        }

        return DB::query()
            ->fromSub($union, 'finishes')
            ->select('position_id')
            ->selectRaw('max(finished_at) as last_finished_at')
            ->groupBy('position_id');
    }
}

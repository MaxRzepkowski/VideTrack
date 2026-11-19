<?php

namespace App\Users;

use App\Accounts\UserCalendar;
use App\Catalog\MediaTypeRegistry;
use App\Enums\ActivityGrouping;
use App\Enums\ActivitySort;
use App\Enums\SortDirection;
use App\Models\MediaItem;
use App\Models\User;
use App\Support\Numbers;
use App\Support\PageInfo;
use App\Support\QueryRow;
use App\Users\Data\ActivityFilters;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Collection as Support;
use LogicException;
use stdClass;

/**
 * The query engine behind the log panels of a user view (history, ratings,
 * favorites). It knows nothing about which panel it serves: the caller names
 * the source (ActivitySource: the table and its date column) and the
 * controls' state (ActivityFilters), and gets back one page plus the day
 * keys. The following panel does not use it — follows point at people and
 * companies, not at logged media.
 *
 * @phpstan-type GroupRow array{groupId: int, at: string, added: string|null}
 */
final readonly class ActivityFeed
{
    // Multiple of the 5 grid columns, so full pages never leave a gap.
    public const PER_PAGE = 50;

    private const DAYS_PER_PAGE = 10;

    /**
     * Id of the ancestor of a given type — the entry's own item, its parent
     * or its grandparent, since a hierarchy is at most three levels deep
     * (show → season → episode). Null when no ancestor is of that type.
     * Takes the type code three times.
     */
    private const ANCESTOR_ID = <<<'SQL'
        case
            when media_items.type = ? then media_items.id
            when parents.type = ? then parents.id
            when grandparents.type = ? then grandparents.id
        end
        SQL;

    public function __construct(
        private MediaTypeRegistry $types,
        private TitleFilters $titles,
        private ViewerDays $days,
    ) {}

    /**
     * The container type this panel has to roll its entries up to, null for
     * the flat listing. Picking a container type in the type filter (a show,
     * a season) means "list me those" — but watching a container logs nothing
     * ON it: completions land on the episodes below, only DROPS sit on the
     * container itself, so the flat listing would answer a "shows" filter
     * with abandoned shows alone. Rolled up it answers with one card per
     * show / season instead, whatever happened underneath.
     */
    public function rollUpType(ActivityFilters $filters): ?string
    {
        return $filters->type !== null && $this->types->get($filters->type)->childType !== null ? $filters->type : null;
    }

    /**
     * One page of a panel. In "grouped by day" mode pagination counts DAYS,
     * not items, so a day never splits across pages; day boundaries follow
     * `$calendar` (the viewer's) and every entry carries its
     * key, so the client groups exactly like the server paginated.
     *
     * `$dayColumns` are extra columns the caller needs from the day scan: the
     * raw rows of the WHOLE filtered set come back, so a panel can total up
     * what each day cost.
     *
     * @param  list<string|Expression>  $dayColumns
     * @return array{
     *     models: Collection<int, Model>,
     *     pagination: PageInfo,
     *     dayOf: array<int, string>,
     *     dayRows: Support<int, stdClass>,
     *     pageDays: array<string, list<int>>,
     * }
     */
    public function page(ActivitySource $source, User $user, User $viewer, ActivityFilters $filters, int $page, UserCalendar $calendar, array $dayColumns = []): array
    {
        $query = $source->entriesOf($user);

        $this->applyFilters($query, $viewer, $source, $filters, $calendar);

        if ($filters->group === ActivityGrouping::Day) {
            // Day groups are chronological by nature; the sort mechanism
            // drives the grid mode.
            $query->orderByDesc("{$source->value}.{$source->dateColumn()}")->orderByDesc("{$source->value}.id");

            return $this->pageOfDays($query, $source, $page, $calendar, $dayColumns);
        }

        $this->applySort($query, $user, $viewer, $source, $filters);

        $paginator = $query->paginate(self::PER_PAGE, page: $page);

        /** @var Collection<int, Model> $models */
        $models = $paginator->getCollection();
        $models->load('mediaItem.parent.parent');

        return [
            'models' => $models,
            'pagination' => new PageInfo($paginator->currentPage(), max(1, $paginator->lastPage()), $paginator->total()),
            'dayOf' => [],
            'dayRows' => new Support,
            'pageDays' => [],
        ];
    }

    /**
     * One page of CONTAINERS instead of entries (see rollUpType): everything
     * logged on a container or anywhere below it collapses into one card,
     * dated by the latest entry beneath it. Pagination counts containers, and
     * the hide toggles judge the container — a show you rated hides, even
     * though the rating never sat on any of its episodes.
     *
     * Grouping happens in the database, ordering in PHP: the groups are the
     * distinct shows / seasons behind the page, a set small enough to sort in
     * memory and far cheaper than re-joining the containers for every sort.
     *
     * @return array{
     *     groups: list<array{item: MediaItem, at: string, day: string|null}>,
     *     pagination: PageInfo,
     * }
     */
    public function groupedPage(ActivitySource $source, User $user, User $viewer, ActivityFilters $filters, int $page, UserCalendar $calendar, string $rollUpType): array
    {
        $query = $source->entriesOf($user);
        $table = $source->value;
        $atColumn = $source->dateColumn();

        $this->applyFilters($query, $viewer, $source, $filters, $calendar, $rollUpType);

        $expression = self::ANCESTOR_ID;
        $bindings = array_fill(0, 3, $rollUpType);

        $rows = $this->groupRows((clone $query)
            ->toBase()
            ->select([])
            ->selectRaw("{$expression} as group_id", $bindings)
            ->selectRaw("max({$table}.{$atColumn}) as at_value")
            ->selectRaw("max({$table}.created_at) as added_value")
            ->groupByRaw($expression, $bindings)
            ->get());

        /** @var Collection<int, MediaItem> $items */
        $items = MediaItem::query()
            ->whereIn('id', $rows->pluck('groupId')->all())
            ->with('parent.parent')
            ->get()
            ->keyBy('id');

        $ratings = ! $filters->sort->isRating()
            ? []
            : Numbers::intMap(self::rater($filters->sort, $viewer, $user)->ratings()->whereIn('media_item_id', $items->keys()->all())->pluck('rating', 'media_item_id'));

        $sorted = $rows
            ->filter(fn (array $row): bool => $items->has($row['groupId']))
            // Latest first, then the chosen mechanism on top: PHP sorts are
            // stable, so this second pass IS the tiebreak. One key per pass —
            // sortBy() with an ARRAY of pairs would read every callable as a
            // comparator instead of a key, and drop the direction with it.
            ->sortByDesc(fn (array $row): string => $row['at']);

        if ($filters->group !== ActivityGrouping::Day) {
            $sorted = $sorted->sortBy(
                fn (array $row) => $this->groupSortKey($row, $filters->sort, $items, $ratings),
                SORT_REGULAR,
                $filters->dir === SortDirection::Desc,
            );

            // The unrated go last whichever way a rating runs: one more stable pass.
            if ($filters->sort->isRating()) {
                $sorted = $sorted->sortBy(fn (array $row): int => isset($ratings[$row['groupId']]) ? 0 : 1);
            }
        }

        $sorted = $sorted->values();

        // Grouped by day, containers are dated by their latest entry and the
        // pagination counts days — exactly like the flat listing. What days
        // do NOT get here is a total: a container is not a cost.
        $days = $this->daysOf($sorted, $calendar);

        [$rowsOfPage, $lastPage, $currentPage] = $filters->group === ActivityGrouping::Day
            ? $this->sliceDays($sorted, $days, $page)
            : $this->sliceEntries($sorted, $page);

        /** @var list<array{item: MediaItem, at: string, day: string|null}> $groups */
        $groups = [];

        foreach ($rowsOfPage as $row) {
            $item = $items->get($row['groupId']);

            if ($item === null) {
                continue;
            }

            $groups[] = [
                'item' => $item,
                'at' => CarbonImmutable::parse($row['at'])->toIso8601String(),
                'day' => $filters->group === ActivityGrouping::Day ? ($days[$row['groupId']] ?? null) : null,
            ];
        }

        return [
            'groups' => $groups,
            'pagination' => new PageInfo($currentPage, $lastPage, $sorted->count()),
        ];
    }

    /**
     * The groups the database answered, each read once. A row whose chain
     * holds no container of that type (a movie play under a "shows" filter)
     * groups into a null bucket, dropped here. The latest entry is a max()
     * over a column every write stamps, so a group always has one.
     *
     * @param  Support<int, stdClass>  $rows
     * @return Support<int, GroupRow>
     */
    private function groupRows(Support $rows): Support
    {
        $groups = [];

        foreach ($rows as $row) {
            $groupId = QueryRow::intOrNull($row, 'group_id');

            if ($groupId !== null) {
                $groups[] = ['groupId' => $groupId, 'at' => QueryRow::text($row, 'at_value'), 'added' => QueryRow::textOrNull($row, 'added_value')];
            }
        }

        return new Support($groups);
    }

    /**
     * The day each container belongs to — the day of its latest entry.
     *
     * @param  Support<int, GroupRow>  $rows
     * @return array<int, string> group id => day key
     */
    private function daysOf(Support $rows, UserCalendar $calendar): array
    {
        $days = [];

        foreach ($rows as $row) {
            $days[$row['groupId']] = $calendar->day(CarbonImmutable::parse($row['at']));
        }

        return $days;
    }

    /**
     * @param  Support<int, GroupRow>  $rows  ordered, latest first
     * @param  array<int, string>  $days
     * @return array{0: Support<int, GroupRow>, 1: int, 2: int}
     */
    private function sliceDays(Support $rows, array $days, int $page): array
    {
        $buckets = $rows->groupBy(fn (array $row): string => $days[$row['groupId']]);

        $lastPage = max(1, (int) ceil($buckets->count() / self::DAYS_PER_PAGE));
        $currentPage = min($page, $lastPage);

        $page = $buckets
            ->slice(($currentPage - 1) * self::DAYS_PER_PAGE, self::DAYS_PER_PAGE)
            ->flatMap(fn (Support $bucket): Support => $bucket);

        return [$page, $lastPage, $currentPage];
    }

    /**
     * @param  Support<int, GroupRow>  $rows
     * @return array{0: Support<int, GroupRow>, 1: int, 2: int}
     */
    private function sliceEntries(Support $rows, int $page): array
    {
        $lastPage = max(1, (int) ceil($rows->count() / self::PER_PAGE));
        $currentPage = min($page, $lastPage);

        return [$rows->slice(($currentPage - 1) * self::PER_PAGE, self::PER_PAGE), $lastPage, $currentPage];
    }

    /**
     * What a rolled-up group sorts on — the same mechanism as applySort(),
     * read off the container instead of off a single entry.
     *
     * @param  GroupRow  $row
     * @param  Collection<int, MediaItem>  $items
     * @param  array<int, int>  $ratings
     */
    private function groupSortKey(array $row, ActivitySort $sort, Collection $items, array $ratings): string|int|float|null
    {
        $item = $items->get($row['groupId']);

        // Rows without a container were filtered out before sorting.
        if ($item === null) {
            return null;
        }

        return match ($sort) {
            ActivitySort::Added => $row['added'],
            ActivitySort::MyRating, ActivitySort::OwnerRating => $ratings[$item->id] ?? null,
            ActivitySort::Community => $item->community_rating,
            ActivitySort::Title => mb_strtolower($item->title),
            ActivitySort::Release => $item->release_date?->toDateString(),
            ActivitySort::Runtime => $item->runtime,
            ActivitySort::Activity => $row['at'],
            ActivitySort::Completions => throw self::listedElsewhere(),
        };
    }

    /**
     * @param  Builder<covariant Model>  $query
     * @param  list<string|Expression>  $dayColumns
     * @return array{
     *     models: Collection<int, Model>,
     *     pagination: PageInfo,
     *     dayOf: array<int, string>,
     *     dayRows: Support<int, stdClass>,
     *     pageDays: array<string, list<int>>,
     * }
     */
    private function pageOfDays(Builder $query, ActivitySource $source, int $page, UserCalendar $calendar, array $dayColumns): array
    {
        $table = $source->value;
        $atColumn = $source->dateColumn();

        $rows = (clone $query)
            ->toBase()
            ->select(["{$table}.id as id", "{$table}.{$atColumn} as at_value", ...$dayColumns])
            ->get();

        /** @var array<string, list<int>> $days ordered day key => entry ids */
        $days = [];
        $dayOf = [];

        foreach ($rows as $row) {
            $id = QueryRow::int($row, 'id');
            $at = QueryRow::textOrNull($row, 'at_value');
            $day = $at === null ? '' : $calendar->day(CarbonImmutable::parse($at));

            $days[$day][] = $id;
            $dayOf[$id] = $day;
            $row->day = $day;
        }

        $lastPage = max(1, (int) ceil(count($days) / self::DAYS_PER_PAGE));
        $currentPage = min($page, $lastPage);

        $pageDays = array_slice($days, ($currentPage - 1) * self::DAYS_PER_PAGE, self::DAYS_PER_PAGE, true);

        /** @var Collection<int, Model> $models */
        $models = $query
            ->whereIn("{$table}.id", collect($pageDays)->flatten()->all())
            ->get();

        $models->load('mediaItem.parent.parent');

        return [
            'models' => $models,
            'pagination' => new PageInfo($currentPage, $lastPage, $rows->count()),
            'dayOf' => $dayOf,
            'dayRows' => $rows,
            'pageDays' => $pageDays,
        ];
    }

    /**
     * `$rollUp` is the container type the panel collapses into (see
     * rollUpType). It moves two things: the type filter stops matching the
     * entry's own item — an entry qualifies by having an ancestor of that
     * type — and the hide toggles judge that ancestor instead of the entry.
     * Rating an episode is not rating its show, and the whole point of "hide
     * rated" on a shows listing is the show's rating.
     *
     * @param  Builder<covariant Model>  $query
     */
    private function applyFilters(Builder $query, User $viewer, ActivitySource $source, ActivityFilters $filters, UserCalendar $calendar, ?string $rollUp = null): void
    {
        $table = $source->value;
        $atColumn = $source->dateColumn();

        // What the hide toggles look at: the entry itself, or the container
        // it rolls up into.
        $subject = $rollUp === null ? 'media_items.id' : '('.self::ANCESTOR_ID.')';
        $subjectBindings = $rollUp === null ? [] : array_fill(0, 3, $rollUp);

        if ($rollUp !== null) {
            $query->whereRaw(self::ANCESTOR_ID.' is not null', $subjectBindings);
        } elseif ($filters->type !== null) {
            $query->where('media_items.type', $filters->type);
        }

        if ($filters->status !== null) {
            $query->where("{$table}.outcome", $filters->status->value);
        }

        $this->days->apply($query, "{$table}.{$atColumn}", $filters, $calendar);

        // The roll-up reads the ancestors too (ANCESTOR_ID), whatever the
        // title filters ask for.
        if ($rollUp !== null) {
            $this->titles->joinAncestors($query);
        }

        // What the viewer already rated or listed hides, on anyone's panel.
        $this->titles->apply($query, $viewer, $filters, $subject, $subjectBindings);
    }

    /**
     * @param  Builder<covariant Model>  $query
     */
    private function applySort(Builder $query, User $user, User $viewer, ActivitySource $source, ActivityFilters $filters): void
    {
        $table = $source->value;
        $atColumn = $source->dateColumn();
        $dir = $filters->dir->value;

        match ($filters->sort) {
            ActivitySort::Activity => $query->orderBy("{$table}.{$atColumn}", $dir),
            ActivitySort::Added => $query->orderBy("{$table}.created_at", $dir),
            ActivitySort::MyRating, ActivitySort::OwnerRating => $this->orderByRating(
                $query,
                $this->ratingOf($query, $source, self::rater($filters->sort, $viewer, $user), $user),
                $filters->dir,
            ),
            ActivitySort::Community => $query->orderBy('media_items.community_rating', $dir),
            ActivitySort::Title => $query->orderByRaw("media_items.title collate nocase {$dir}"),
            ActivitySort::Release => $query->orderBy('media_items.release_date', $dir),
            ActivitySort::Runtime => $query->orderBy('media_items.runtime', $dir),
            ActivitySort::Completions => throw self::listedElsewhere(),
        };

        $query->orderByDesc("{$table}.{$atColumn}")->orderByDesc("{$table}.id");
    }

    /**
     * Whose rating a rating axis reads. On their own panel the owner is the
     * viewer, so both axes read the same.
     */
    private static function rater(ActivitySort $sort, User $viewer, User $owner): User
    {
        return match ($sort) {
            ActivitySort::MyRating => $viewer,
            ActivitySort::OwnerRating => $owner,
            default => throw new LogicException("Sorting by {$sort->value} reads no rating."),
        };
    }

    /**
     * The column holding `$rater`'s rating of each entry's item: the ratings
     * panel's own column when it is the owner's, a join otherwise.
     *
     * @param  Builder<covariant Model>  $query
     * @return literal-string
     */
    private function ratingOf(Builder $query, ActivitySource $source, User $rater, User $owner): string
    {
        if ($source === ActivitySource::Ratings && $rater->is($owner)) {
            return 'ratings.rating';
        }

        $query->leftJoin('ratings as rater_ratings', function (JoinClause $join) use ($rater) {
            $join->on('rater_ratings.media_item_id', '=', 'media_items.id')
                ->where('rater_ratings.user_id', $rater->id);
        });

        return 'rater_ratings.rating';
    }

    /**
     * The unrated go last whichever way the rating runs.
     *
     * @param  Builder<covariant Model>  $query
     * @param  literal-string  $column
     */
    private function orderByRating(Builder $query, string $column, SortDirection $dir): void
    {
        $query->orderByRaw("{$column} is null")->orderBy($column, $dir->value);
    }

    /** Sorted by times finished a panel lists titles, not entries: {@see CompletionsFeed}. */
    private static function listedElsewhere(): LogicException
    {
        return new LogicException('Times finished is listed by CompletionsFeed.');
    }
}

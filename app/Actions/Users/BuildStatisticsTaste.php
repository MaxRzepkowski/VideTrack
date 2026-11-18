<?php

namespace App\Actions\Users;

use App\Catalog\ItemMetadata;
use App\Catalog\MediaItemSummaries;
use App\Catalog\MediaTypeRegistry;
use App\Catalog\ProgressUnit;
use App\Http\Data\StatisticsTaste;
use App\Library\ReadingTime;
use App\Models\MediaItem;
use App\Models\User;
use App\Users\Statistics\Data\GenreStat;
use App\Users\Statistics\Data\HistoryEntry;
use App\Users\Statistics\Data\ReleaseDot;
use App\Users\Statistics\Data\TimeTitle;
use App\Users\Statistics\Data\WholeLength;
use App\Users\Statistics\StatisticsScope;
use App\Users\Statistics\TitleLookups;
use Illuminate\Support\Collection;

/**
 * The "genres and release years" section: the titles the most time went to, the years the period's titles came out in, their
 * genres and how long the films were. Time is minutes watched and played,
 * and reading estimated at the user's pace (see ReadingTime); one type that
 * is read, on its own, counts in its own unit with the estimate beside it.
 */
final readonly class BuildStatisticsTaste
{
    /** Titles in the "most time" row. */
    private const TOP = 8;

    public function __construct(
        private MediaTypeRegistry $types,
        private TitleLookups $lookups,
    ) {}

    public function execute(User $viewer, StatisticsScope $scope): StatisticsTaste
    {
        $readIn = $this->readIn($scope->type);
        $titleIds = TitleLookups::rootIds($scope->counted);
        $titles = $this->lookups->items($titleIds);
        $ratings = $this->lookups->ratings($viewer, $titleIds);

        $reading = new ReadingTime($viewer->preferences);
        $amounts = [];
        $estimates = [];
        $parts = [];

        foreach ($scope->shown as $entry) {
            if (isset($titles[$entry->rootId])) {
                $estimate = $entry->readMinutes($reading);
                $amounts[$entry->rootId] = ($amounts[$entry->rootId] ?? 0) + ($readIn === null ? $entry->minutes($reading) : $this->amountRead($readIn, $entry));

                if ($estimate !== null) {
                    $estimates[$entry->rootId] = ($estimates[$entry->rootId] ?? 0) + $estimate;
                }

                if ($entry->completed && $entry->itemId !== $entry->rootId) {
                    $parts[$entry->rootId] = ($parts[$entry->rootId] ?? 0) + 1;
                }
            }
        }

        return new StatisticsTaste(
            readIn: $readIn,
            readingEstimated: $readIn === null && array_filter($estimates) !== [],
            top: $this->top($titles, $amounts, $estimates, $parts),
            releases: $this->releases($titles, $ratings),
            genres: $this->genres($titles, $amounts, $ratings),
            lengths: $this->lengths($titles, $scope),
        );
    }

    /** The unit a view's totals are read in when the one type on show is read; null for totals in time. */
    private function readIn(?string $type): ?ProgressUnit
    {
        $unit = $type === null ? null : $this->types->get($type)->progressUnit;

        return $unit?->isRead() === true ? $unit : null;
    }

    /** What an entry read, in the unit of the one type on show. */
    private function amountRead(ProgressUnit $unit, HistoryEntry $entry): int
    {
        return $entry->unit === $unit->value ? $entry->amount : 0;
    }

    /**
     * @param  Collection<int, MediaItem>  $titles
     * @param  array<int, int>  $amounts
     * @param  array<int, int>  $estimates  minutes read, estimated, per title that was read
     * @param  array<int, int>  $parts
     * @return list<TimeTitle>
     */
    private function top(Collection $titles, array $amounts, array $estimates, array $parts): array
    {
        arsort($amounts);

        $top = [];

        foreach (array_slice(array_filter($amounts), 0, self::TOP, true) as $rootId => $amount) {
            $title = $titles->get($rootId);

            if ($title !== null) {
                $part = $this->types->get($title->type)->childType === null ? null : ($parts[$rootId] ?? 0);
                $top[] = new TimeTitle(MediaItemSummaries::summary($title), $amount, $estimates[$rootId] ?? null, $part);
            }
        }

        return $top;
    }

    /**
     * @param  Collection<int, MediaItem>  $titles
     * @param  array<int, int>  $ratings
     * @return list<ReleaseDot>
     */
    private function releases(Collection $titles, array $ratings): array
    {
        $dots = [];

        foreach ($titles as $title) {
            if ($title->year !== null) {
                $dots[] = new ReleaseDot($title->year, $title->type, $ratings[$title->id] ?? null);
            }
        }

        return $dots;
    }

    /**
     * @param  Collection<int, MediaItem>  $titles
     * @param  array<int, int>  $amounts
     * @param  array<int, int>  $ratings
     * @return list<GenreStat>
     */
    private function genres(Collection $titles, array $amounts, array $ratings): array
    {
        $genres = [];

        foreach ($titles as $title) {
            foreach (array_unique(ItemMetadata::fromStored($title->metadata)->genres) as $genre) {
                $genres[$genre] ??= ['titles' => 0, 'amount' => 0, 'ratings' => []];
                $genres[$genre]['titles']++;
                $genres[$genre]['amount'] += $amounts[$title->id] ?? 0;

                if (isset($ratings[$title->id])) {
                    $genres[$genre]['ratings'][] = $ratings[$title->id];
                }
            }
        }

        uasort($genres, fn (array $a, array $b): int => $b['titles'] <=> $a['titles']);

        return array_map(
            fn (string|int $name, array $genre): GenreStat => new GenreStat(
                name: (string) $name,
                titles: $genre['titles'],
                amount: $genre['amount'],
                averageRating: $genre['ratings'] === [] ? null : round(array_sum($genre['ratings']) / count($genre['ratings']), 2),
                rated: count($genre['ratings']),
            ),
            array_keys($genres),
            $genres,
        );
    }

    /**
     * How long the titles watched whole were, per such type on show: the
     * longest and the average of their runtimes, and every viewing's time.
     *
     * @param  Collection<int, MediaItem>  $titles
     * @return list<WholeLength>
     */
    private function lengths(Collection $titles, StatisticsScope $scope): array
    {
        $lengths = [];

        foreach ($this->types->titleCodes() as $code) {
            if (! $this->types->get($code)->releasesWhole() || ($scope->type !== null && $scope->type !== $code)) {
                continue;
            }

            $timed = $titles->filter(fn (MediaItem $title): bool => $title->type === $code && $title->runtime !== null && $title->runtime > 0);
            $longest = $timed->sortByDesc('runtime')->first();

            if ($longest === null || $longest->runtime === null) {
                continue;
            }

            $runtimes = $timed->map(fn (MediaItem $title): int => $title->runtime ?? 0)->all();

            $lengths[] = new WholeLength(
                type: $code,
                titles: $titles->filter(fn (MediaItem $title): bool => $title->type === $code)->count(),
                total: array_sum(array_map(fn (HistoryEntry $entry): int => $entry->rootType === $code ? $entry->loggedMinutes() : 0, $scope->shown)),
                average: (int) round(array_sum($runtimes) / count($runtimes)),
                longest: MediaItemSummaries::summary($longest),
                longestRuntime: $longest->runtime,
            );
        }

        return $lengths;
    }
}

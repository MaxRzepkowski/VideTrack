<?php

namespace App\Actions\Users;

use App\Catalog\Data\TypeCount;
use App\Catalog\MediaItemSummaries;
use App\Catalog\MediaTypeRegistry;
use App\Http\Data\StatisticsPage;
use App\Library\Data\WatchStats;
use App\Library\RatingScale;
use App\Library\ReadingTime;
use App\Models\MediaItem;
use App\Models\User;
use App\Users\Statistics\Data\Bookend;
use App\Users\Statistics\Data\HistoryEntry;
use App\Users\Statistics\Data\PeriodSummary;
use App\Users\Statistics\Data\PeriodTitles;
use App\Users\Statistics\Data\TypeTally;
use App\Users\Statistics\Data\YearCount;
use App\Users\Statistics\PeriodMarks;
use App\Users\Statistics\StatisticsScope;
use App\Users\Statistics\TitleLookups;
use App\Users\Statistics\TitleMark;

/**
 * The top of the statistics page for one period and type: the year axis and
 * the type picker, the period's card, the tiles, what became of its titles,
 * and its first and last finished entry. Counted from the user's own history
 * only.
 */
final readonly class BuildStatisticsPage
{
    public function __construct(
        private MediaTypeRegistry $types,
        private MediaItemSummaries $summaries,
        private TitleLookups $lookups,
        private PeriodMarks $marks,
    ) {}

    public function execute(User $viewer, StatisticsScope $scope): StatisticsPage
    {
        $marks = $this->marks->of($viewer, $scope);
        $reading = new ReadingTime($viewer->preferences);
        $rated = $this->lookups->periodRatings($viewer, $marks);
        $ratings = $rated->ratings;
        $completions = $scope->completions;

        return new StatisticsPage(
            period: $scope->span(),
            type: $scope->type,
            years: $this->years($scope->entries),
            allTime: count(array_filter($scope->entries, fn (HistoryEntry $entry): bool => $entry->completed)),
            types: $this->typeOptions($scope),
            earliest: $scope->firstCompletion()?->day,
            summary: new PeriodSummary(
                time: $this->time($scope, $reading),
                readingMinutes: array_sum(array_map(fn (HistoryEntry $entry): int => $entry->readMinutes($reading) ?? 0, $scope->shown)),
                activeDays: count(array_unique(array_map(fn (HistoryEntry $entry): string => $entry->day, $completions))),
                ratings: count($ratings),
                averageRating: $this->average($ratings),
                ratingCounts: $this->ratingCounts($ratings),
                // One type's view only, where the two scales measure the same kind of thing.
                communityDelta: $scope->type === null ? null : $rated->averageGap(),
            ),
            tiles: $this->tiles($scope, $marks, $reading),
            titles: $this->titles($marks),
            first: $this->bookend($completions[0] ?? null),
            last: $this->bookend($completions === [] ? null : $completions[array_key_last($completions)]),
        );
    }

    /**
     * The time the period took, per type: watched and played as logged,
     * read as estimated (see ReadingTime); dropped attempts count too.
     */
    private function time(StatisticsScope $scope, ReadingTime $reading): TypeTally
    {
        $minutes = [];

        foreach ($scope->shown as $entry) {
            $minutes[$entry->rootType] = ($minutes[$entry->rootType] ?? 0) + $entry->minutes($reading);
        }

        $minutes = array_filter($minutes);

        return new TypeTally(array_sum($minutes), TypeCount::inOrder($minutes, $this->types));
    }

    /**
     * @param  list<HistoryEntry>  $entries
     * @return list<YearCount>
     */
    private function years(array $entries): array
    {
        $years = [];

        foreach ($entries as $entry) {
            if ($entry->completed) {
                $year = (int) substr($entry->day, 0, 4);
                $years[$year] = ($years[$year] ?? 0) + 1;
            }
        }

        ksort($years);

        return array_map(fn (int $year, int $count): YearCount => new YearCount($year, $count), array_keys($years), $years);
    }

    /**
     * Every type the user has a title of, with what was finished in the
     * period — the picker's options, whatever the period holds.
     *
     * @return list<TypeCount>
     */
    private function typeOptions(StatisticsScope $scope): array
    {
        $present = [];

        foreach ($scope->everything as $entry) {
            if ($entry->countsTitle) {
                $present[$entry->rootType] = 0;
            }
        }

        foreach ($scope->inPeriod as $entry) {
            if ($entry->completed) {
                $present[$entry->rootType] = ($present[$entry->rootType] ?? 0) + 1;
            }
        }

        return array_map(
            fn (string $code): TypeCount => new TypeCount($code, $present[$code]),
            $this->types->ordered(array_keys($present)),
        );
    }

    /**
     * Each shown type's tile over the period: time (or the type's unit) from
     * every entry, dropped viewings included; items and plays counted by the
     * dashboard's rule (UserHistory's `countsTitle`: what was finished, or
     * every attempt where every attempt counts). An open attempt with nothing
     * logged yet has no day, so unlike the dashboard's it belongs to no
     * period. Its titles are the period's titles of the type, marked ones
     * included, so the tile counts what the rest of the page counts: a show
     * dropped in the period is one of its shows even with no episode in it.
     *
     * @param  array<int, array{type: string, mark: TitleMark}>  $marks
     * @return array<string, WatchStats>
     */
    private function tiles(StatisticsScope $scope, array $marks, ReadingTime $reading): array
    {
        $tiles = [];
        $titles = array_count_values(array_map(fn (array $title): string => $title['type'], $marks));

        foreach ($scope->type === null ? $this->types->titleCodes() : [$scope->type] as $code) {
            $entries = array_filter($scope->shown, fn (HistoryEntry $entry): bool => $entry->rootType === $code);
            $counted = array_filter($entries, fn (HistoryEntry $entry): bool => $entry->countsTitle);
            $amount = array_sum(array_map(fn (HistoryEntry $entry): int => $entry->amount, $entries));

            $tiles[$code] = new WatchStats(
                minutes: $amount,
                watched: count(array_unique(array_map(fn (HistoryEntry $entry): int => $entry->itemId, $counted))),
                plays: count($counted),
                titles: $titles[$code] ?? 0,
                estimatedMinutes: $reading->of($this->types->get($code)->progressUnit, $amount),
            );
        }

        return $tiles;
    }

    /**
     * How the period's titles split by what each ended the period as.
     *
     * @param  array<int, array{type: string, mark: TitleMark}>  $marks
     */
    private function titles(array $marks): PeriodTitles
    {
        $marked = fn (TitleMark $which): TypeTally => $this->titleTally(array_filter($marks, fn (array $title): bool => $title['mark'] === $which));

        return new PeriodTitles(
            titles: $this->titleTally($marks),
            completed: $marked(TitleMark::Completed),
            inProgress: $marked(TitleMark::InProgress),
            onHold: $marked(TitleMark::OnHold),
            dropped: $marked(TitleMark::Dropped),
        );
    }

    /**
     * @param  array<int, array{type: string, mark: TitleMark}>  $titles
     */
    private function titleTally(array $titles): TypeTally
    {
        return $this->tally(array_values(array_map(fn (array $title): string => $title['type'], $titles)));
    }

    private function bookend(?HistoryEntry $entry): ?Bookend
    {
        if ($entry === null) {
            return null;
        }

        $item = MediaItem::query()->with('parent.parent')->findOrFail($entry->itemId);

        return new Bookend($this->summaries->inContext($item), $entry->at->toIso8601String());
    }

    /**
     * How many titles got each rating, lowest first.
     *
     * @param  array<int, int>  $ratings
     * @return list<int>
     */
    private function ratingCounts(array $ratings): array
    {
        $counts = array_fill(RatingScale::MIN, RatingScale::MAX - RatingScale::MIN + 1, 0);

        foreach ($ratings as $rating) {
            $counts[$rating]++;
        }

        return array_values($counts);
    }

    /**
     * @param  array<int, int>  $ratings
     */
    private function average(array $ratings): ?float
    {
        return $ratings === [] ? null : round(array_sum($ratings) / count($ratings), 2);
    }

    /**
     * @param  list<string>  $types  one entry per thing counted
     */
    private function tally(array $types): TypeTally
    {
        return new TypeTally(count($types), TypeCount::inOrder(array_count_values($types), $this->types));
    }
}

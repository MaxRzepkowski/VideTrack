<?php

namespace App\Actions\Users;

use App\Accounts\UserCalendar;
use App\Catalog\Hierarchy;
use App\Catalog\MediaItemSummaries;
use App\Http\Data\StatisticsRecords;
use App\Library\ReadingTime;
use App\Models\MediaItem;
use App\Models\User;
use App\Users\Statistics\DailyCounts;
use App\Users\Statistics\Data\BusiestDay;
use App\Users\Statistics\Data\DayCount;
use App\Users\Statistics\Data\HistoryEntry;
use App\Users\Statistics\Data\Marathon;
use App\Users\Statistics\Data\PartNumber;
use App\Users\Statistics\Data\Streak;
use App\Users\Statistics\StatisticsScope;
use App\Users\Statistics\TitleLookups;
use Carbon\CarbonImmutable;
use LogicException;

/**
 * The "streaks and marathons" section: the longest run of days with
 * something finished, the busiest day and its week, and the most parts of
 * one title finished in a day. Ties go to what happened first; a record of
 * one (one day, one episode) is no record.
 */
final readonly class BuildStatisticsRecords
{
    /** The fewest days in a row, or parts of a title in a day, that make a record. */
    private const RECORD_MIN = 2;

    /** Days shown on either side of the streak. */
    public const STREAK_MARGIN_DAYS = 14;

    public function __construct(private TitleLookups $lookups) {}

    public function execute(User $viewer, StatisticsScope $scope): StatisticsRecords
    {
        $days = DailyCounts::of($scope->completions);
        $firstWeekday = $viewer->preferences->firstWeekday($viewer->preferredLocale());

        return new StatisticsRecords(
            streak: $this->streak(array_keys($days->counts), $scope),
            busiestDay: $this->busiestDay($days->busiest(), $scope, $firstWeekday),
            marathon: $this->marathon($scope->completions, new ReadingTime($viewer->preferences)),
            firstWeekday: $firstWeekday,
        );
    }

    /**
     * The longest run of days, with two weeks either side of it as far as
     * the period and today go.
     *
     * @param  list<string>  $days  Y-m-d, oldest first
     */
    private function streak(array $days, StatisticsScope $scope): ?Streak
    {
        $best = null;
        $start = '';
        $previous = null;
        $length = 0;

        foreach ($days as $day) {
            $date = CarbonImmutable::parse($day);
            $continues = $previous !== null && $previous->addDay()->isSameDay($date);
            $start = $continues ? $start : $day;
            $length = $continues ? $length + 1 : 1;
            $previous = $date;

            if ($best === null || $length > $best['days']) {
                $best = ['from' => $start, 'to' => $day, 'days' => $length];
            }
        }

        if ($best === null || $best['days'] < self::RECORD_MIN) {
            return null;
        }

        $span = $scope->span();
        $shownFrom = max(CarbonImmutable::parse($best['from'])->subDays(self::STREAK_MARGIN_DAYS)->toDateString(), $span->from ?? $best['from']);
        $shownTo = min(CarbonImmutable::parse($best['to'])->addDays(self::STREAK_MARGIN_DAYS)->toDateString(), $span->to ?? $scope->today, $scope->today);

        return new Streak(
            from: $best['from'],
            to: $best['to'],
            days: $best['days'],
            shownFrom: $shownFrom,
            shownTo: $shownTo,
            otherDays: array_values(array_filter(
                $days,
                fn (string $day): bool => $day >= $shownFrom && $day <= $shownTo && ($day < $best['from'] || $day > $best['to']),
            )),
        );
    }

    /**
     * The busiest day with its whole week, counted from the type's whole
     * history: the week may reach past the period.
     */
    private function busiestDay(?DayCount $busiest, StatisticsScope $scope, int $firstWeekday): ?BusiestDay
    {
        if ($busiest === null) {
            return null;
        }

        $date = CarbonImmutable::parse($busiest->day);
        $start = UserCalendar::weekStart($date, $firstWeekday);
        $end = $start->addDays(6)->toDateString();
        $finished = array_filter($scope->entries, fn (HistoryEntry $entry): bool => $entry->completed && $entry->day >= $start->toDateString() && $entry->day <= $end);
        $counts = DailyCounts::of(array_values($finished))->counts;

        $week = [];

        for ($offset = 0; $offset < 7; $offset++) {
            $day = $start->addDays($offset)->toDateString();
            $week[] = new DayCount($day, $counts[$day] ?? 0);
        }

        return new BusiestDay($busiest, $week);
    }

    /**
     * The title with the most parts finished in one day; a tie goes to the
     * longer day, then the earlier one.
     *
     * @param  list<HistoryEntry>  $completions  oldest first
     */
    private function marathon(array $completions, ReadingTime $reading): ?Marathon
    {
        $days = [];

        foreach ($completions as $entry) {
            if ($entry->itemId === $entry->rootId) {
                continue;
            }

            $key = $entry->rootId.' '.$entry->day;
            $days[$key] ??= ['root' => $entry->rootId, 'day' => $entry->day, 'parts' => [], 'minutes' => 0];
            $days[$key]['parts'][$entry->itemId] = true;
            $days[$key]['minutes'] += $entry->minutes($reading);
        }

        $best = null;

        foreach ($days as $day) {
            if ($best === null || [count($day['parts']), $day['minutes']] > [count($best['parts']), $best['minutes']]) {
                $best = $day;
            }
        }

        if ($best === null || count($best['parts']) < self::RECORD_MIN) {
            return null;
        }

        $title = $this->lookups->items([$best['root']])->get($best['root']);

        if ($title === null) {
            return null;
        }

        $numbers = $this->partNumbers(array_keys($best['parts']));

        return new Marathon(
            media: MediaItemSummaries::summary($title),
            day: $best['day'],
            parts: count($best['parts']),
            first: $numbers[0],
            last: $numbers[count($numbers) - 1],
            minutes: $best['minutes'],
        );
    }

    /**
     * The parts' numbers, lowest first: by season, then by number.
     *
     * @param  non-empty-list<int>  $ids
     * @return non-empty-list<PartNumber>
     */
    private function partNumbers(array $ids): array
    {
        $parts = $this->lookups->items($ids, ['number', 'parent_id']);
        $parentIds = array_values(array_unique(array_filter($parts->map(fn (MediaItem $part): ?int => $part->parent_id)->values()->all())));
        $parents = $this->lookups->items($parentIds, ['number', 'parent_id']);

        $numbers = [];

        foreach ($ids as $id) {
            $part = $parts->get($id) ?? throw new LogicException("Part {$id} was finished but not read.");
            $parent = ($part->parent_id === null ? null : $parents->get($part->parent_id)) ?? throw new LogicException("Part {$id} sits under no title.");
            $numbers[] = new PartNumber(Hierarchy::seasonNumber($parent), $part->number);
        }

        usort($numbers, fn (PartNumber $a, PartNumber $b): int => [$a->season ?? 0, $a->number ?? 0] <=> [$b->season ?? 0, $b->number ?? 0]);

        return $numbers;
    }
}

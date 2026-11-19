<?php

namespace App\Actions\Users;

use App\Accounts\UserCalendar;
use App\Catalog\MediaItemSummaries;
use App\Models\Play;
use App\Models\User;
use App\Support\PageInfo;
use App\Users\ActivityFeed;
use App\Users\ActivitySource;
use App\Users\CompletionsFeed;
use App\Users\Data\ActivityEntry;
use App\Users\Data\ActivityFilters;
use App\Users\HistoryDayTotals;
use Illuminate\Database\Eloquent\Collection;

/**
 * One page of the history panel, in whichever of its three forms the
 * controls ask for: the plays themselves, the shows or seasons they roll up
 * into, or the positions by times finished. Only the plays are a cost, so
 * only they total up their days.
 */
final readonly class BuildHistoryEntries
{
    public function __construct(
        private ActivityFeed $feed,
        private CompletionsFeed $completions,
        private HistoryDayTotals $totals,
        private MediaItemSummaries $cards,
    ) {}

    /**
     * @return array{entries: list<ActivityEntry>, pagination: PageInfo, dayTotals: array<string, array<string, int>>}
     */
    public function execute(User $user, ActivityFilters $filters, int $page, UserCalendar $calendar): array
    {
        if ($filters->byCompletions()) {
            $result = $this->completions->page($user, $filters, $page, $calendar);

            return [
                'entries' => array_map(fn (array $row): ActivityEntry => new ActivityEntry(
                    id: null,
                    at: $row['at']?->toIso8601String(),
                    media: $this->cards->inContext($row['entry']->mediaItem),
                    completions: $row['entry']->completed_cycles,
                ), $result['entries']),
                'pagination' => $result['pagination'],
                'dayTotals' => [],
            ];
        }

        // Filtering by a container type (shows, seasons) collapses the plays
        // into the containers themselves — see ActivityFeed::rollUpType.
        $rollUp = $this->feed->rollUpType($filters);

        if ($rollUp !== null) {
            $result = $this->feed->groupedPage(ActivitySource::Plays, $user, $filters, $page, $calendar, $rollUp);

            return [
                'entries' => array_map(fn (array $group): ActivityEntry => new ActivityEntry(
                    id: null,
                    at: $group['at'],
                    media: $this->cards->inContext($group['item']),
                    day: $group['day'],
                ), $result['groups']),
                'pagination' => $result['pagination'],
                'dayTotals' => [],
            ];
        }

        // The day scan needs what a play cost, on top of its date.
        $result = $this->feed->page(ActivitySource::Plays, $user, $filters, $page, $calendar, $this->totals->columns());

        /** @var Collection<int, Play> $plays */
        $plays = $result['models'];

        return [
            'entries' => array_values(array_map(fn (Play $play): ActivityEntry => new ActivityEntry(
                id: $play->id,
                at: $play->watched_at->toIso8601String(),
                media: $this->cards->inContext($play->mediaItem),
                day: $result['dayOf'][$play->id] ?? null,
                outcome: $play->outcome,
            ), $plays->all())),
            'pagination' => $result['pagination'],
            'dayTotals' => $this->totals->of($result['dayRows'], $result['pageDays']),
        ];
    }
}

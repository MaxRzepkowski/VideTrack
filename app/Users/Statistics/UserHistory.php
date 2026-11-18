<?php

namespace App\Users\Statistics;

use App\Accounts\UserCalendar;
use App\Catalog\MediaTypeRegistry;
use App\Enums\PlayOutcome;
use App\Library\PlayCost;
use App\Models\Play;
use App\Models\User;
use App\Support\QueryRow;
use App\Users\Statistics\Data\HistoryEntry;
use Carbon\CarbonImmutable;

/**
 * A user's whole history in one pass, oldest first, each entry under the
 * title the library projection files it and on the user's own clock.
 *
 * Something is finished when its entry is completed and its type is played
 * (a film, an episode, a game, a book) — a dropped film is not a watched
 * film. A title belongs to a period the way the dashboard tiles count titles:
 * through something finished, or, for a type whose every attempt counts (a
 * game, a book), through any attempt, dropped ones too. Time is what PlayCost
 * prices each entry at, dropped viewings included. An open attempt with no
 * dated entry yet belongs to no period.
 *
 * The page reads the whole history rather than one period: the year axis
 * counts every year, and a title is new in the period it first counted in.
 */
final readonly class UserHistory
{
    public function __construct(
        private MediaTypeRegistry $types,
        private PlayCost $cost,
    ) {}

    /** One view of the page: the whole history cut to a period and a type. */
    public function scope(User $user, StatisticsPeriod $period, ?string $type): StatisticsScope
    {
        return new StatisticsScope($period, $type, $this->of($user), UserCalendar::of($user)->today()->toDateString());
    }

    /**
     * @return list<HistoryEntry>
     */
    public function of(User $user): array
    {
        $calendar = UserCalendar::of($user);

        $plays = Play::query()
            ->where('plays.user_id', $user->id)
            ->join('media_items', 'media_items.id', '=', 'plays.media_item_id')
            ->toBase();

        $rows = $this->cost->joinTitles($plays)
            ->orderBy('plays.watched_at')
            ->orderBy('plays.id')
            ->selectRaw('plays.watched_at as watched_at, plays.outcome as outcome, plays.amount as amount')
            ->selectRaw('media_items.id as item_id, media_items.type as item_type, media_items.runtime as runtime')
            ->selectRaw('coalesce(roots.id, media_items.id) as root_id, coalesce(roots.type, media_items.type) as root_type, roots.runtime as title_runtime')
            ->get();

        $entries = [];

        foreach ($rows as $row) {
            $itemType = QueryRow::text($row, 'item_type');
            $rootType = QueryRow::text($row, 'root_type');

            // A type whose module the instance switched off has nothing to count into.
            if (! $this->types->has($itemType) || ! $this->types->has($rootType)) {
                continue;
            }

            $definition = $this->types->get($itemType);
            $outcome = PlayOutcome::from(QueryRow::text($row, 'outcome'));
            $cost = $this->cost->of(
                $itemType,
                $outcome,
                QueryRow::intOrNull($row, 'amount'),
                QueryRow::intOrNull($row, 'runtime'),
                QueryRow::intOrNull($row, 'title_runtime'),
            );
            $at = $calendar->moment(CarbonImmutable::parse(QueryRow::text($row, 'watched_at'), 'UTC'));

            $entries[] = new HistoryEntry(
                itemId: QueryRow::int($row, 'item_id'),
                itemType: $itemType,
                rootId: QueryRow::int($row, 'root_id'),
                rootType: $rootType,
                outcome: $outcome,
                completed: $definition->playable && $outcome === PlayOutcome::Completed,
                countsTitle: $definition->playable && ($definition->countsEveryAttempt() || $outcome === PlayOutcome::Completed),
                unit: $cost[0] ?? null,
                amount: $cost[1] ?? 0,
                at: $at,
                day: $at->toDateString(),
            );
        }

        return $entries;
    }
}

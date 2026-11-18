<?php

namespace App\Actions\Users;

use App\Catalog\MediaItemSummaries;
use App\Http\Data\StatisticsRatings;
use App\Models\MediaItem;
use App\Models\User;
use App\Users\Statistics\Data\RatedTitle;
use App\Users\Statistics\Data\RatingGap;
use App\Users\Statistics\Data\RatingPoint;
use App\Users\Statistics\PeriodMarks;
use App\Users\Statistics\PeriodRatings;
use App\Users\Statistics\StatisticsScope;
use App\Users\Statistics\TitleLookups;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\App;
use LogicException;

/**
 * How the user rated the period's titles — the ones the period card counts
 * the ratings of — against everyone else's average on the service: every
 * rated title that has one, the best rated, and the titles rated furthest
 * above and below the others.
 */
final readonly class BuildStatisticsRatings
{
    /** Posters in the row of the best rated. */
    private const BEST = 8;

    /** Titles on each side of the disagreements. */
    private const GAPS = 5;

    public function __construct(
        private TitleLookups $lookups,
        private PeriodMarks $marks,
    ) {}

    public function execute(User $viewer, StatisticsScope $scope): StatisticsRatings
    {
        $rated = $this->lookups->periodRatings($viewer, $this->marks->of($viewer, $scope));
        $ratings = $rated->ratings;
        $titles = $this->lookups->items(array_keys($ratings), ['type', 'title', 'localized_titles']);
        $locale = App::getLocale();
        $names = $titles->map(fn (MediaItem $title): string => $title->titleIn($locale))->all();
        $name = fn (int $id): string => $names[$id] ?? throw new LogicException("Title {$id} was rated but not read.");

        $best = array_keys($ratings);
        usort($best, fn (int $a, int $b): int => [$ratings[$b], $name($a)] <=> [$ratings[$a], $name($b)]);

        $gaps = $rated->gaps();
        $higher = $rated->higher();
        $lower = $rated->lower();
        usort($higher, fn (int $a, int $b): int => [$gaps[$b], $name($a)] <=> [$gaps[$a], $name($b)]);
        usort($lower, fn (int $a, int $b): int => [$gaps[$a], $name($a)] <=> [$gaps[$b], $name($b)]);

        $best = array_slice($best, 0, self::BEST);
        $furthestHigher = array_slice($higher, 0, self::GAPS);
        $furthestLower = array_slice($lower, 0, self::GAPS);
        $shown = $this->lookups->items(array_values(array_unique([...$best, ...$furthestHigher, ...$furthestLower])));

        return new StatisticsRatings(
            points: $this->points($titles, $rated),
            ratedHigher: count($higher),
            ratedLower: count($lower),
            ratedSame: $rated->same(),
            best: $this->rated($best, $shown, $ratings),
            bestTied: $ratings === [] ? 0 : count(array_keys($ratings, max($ratings), true)),
            higher: $this->gaps($furthestHigher, $shown, $rated),
            lower: $this->gaps($furthestLower, $shown, $rated),
        );
    }

    /**
     * The rated titles everyone else rated too, those of one type rated
     * alike by both folded into one point.
     *
     * @param  Collection<int, MediaItem>  $titles
     * @return list<RatingPoint>
     */
    private function points(Collection $titles, PeriodRatings $rated): array
    {
        $points = [];

        foreach ($rated->community as $id => $community) {
            $type = ($titles->get($id) ?? throw new LogicException("Title {$id} was rated but not read."))->type;
            $key = "{$type}|{$rated->ratings[$id]}|{$community}";
            $points[$key] = new RatingPoint($type, $rated->ratings[$id], $community, ($points[$key]->titles ?? 0) + 1);
        }

        return array_values($points);
    }

    /**
     * @param  list<int>  $ids
     * @param  Collection<int, MediaItem>  $shown
     * @param  array<int, int>  $ratings
     * @return list<RatedTitle>
     */
    private function rated(array $ids, Collection $shown, array $ratings): array
    {
        return array_map(fn (int $id): RatedTitle => new RatedTitle(
            MediaItemSummaries::summary($shown->get($id) ?? throw new LogicException("Title {$id} was rated but not read.")),
            $ratings[$id],
        ), $ids);
    }

    /**
     * @param  list<int>  $ids
     * @param  Collection<int, MediaItem>  $shown
     * @return list<RatingGap>
     */
    private function gaps(array $ids, Collection $shown, PeriodRatings $rated): array
    {
        return array_map(fn (int $id): RatingGap => new RatingGap(
            MediaItemSummaries::summary($shown->get($id) ?? throw new LogicException("Title {$id} was rated but not read.")),
            $rated->ratings[$id],
            $rated->community[$id],
        ), $ids);
    }
}

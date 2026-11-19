<?php

namespace App\Users\Statistics;

use App\Accounts\UserCalendar;
use App\Actions\Users\BuildStatisticsActivity;
use App\Actions\Users\BuildStatisticsCreators;
use App\Actions\Users\BuildStatisticsOrigins;
use App\Actions\Users\BuildStatisticsPage;
use App\Actions\Users\BuildStatisticsRatings;
use App\Actions\Users\BuildStatisticsRecords;
use App\Actions\Users\BuildStatisticsTaste;
use App\Models\User;
use Closure;

/**
 * Each part of a user's statistics for one period and type, as the page and
 * the API receive it: kept once worked out (StatisticsCache), so a view seen
 * before reads no history at all.
 */
final readonly class StatisticsSections
{
    public function __construct(
        private BuildStatisticsPage $summary,
        private BuildStatisticsActivity $activity,
        private BuildStatisticsTaste $taste,
        private BuildStatisticsOrigins $origins,
        private BuildStatisticsCreators $creators,
        private BuildStatisticsRatings $ratings,
        private BuildStatisticsRecords $records,
        private StatisticsCache $cache,
    ) {}

    /**
     * @param  Closure(): StatisticsScope  $scope  the period's history, read only when the section was not kept; a caller asking for several sections reads it once
     * @return array<string, mixed>
     */
    public function of(User $viewer, StatisticsPeriod $period, ?string $type, StatisticsSection $section, Closure $scope): array
    {
        $today = UserCalendar::of($viewer)->today()->toDateString();

        return $this->cache->remember($viewer, $period, $type, $today, $section, fn (): object => match ($section) {
            StatisticsSection::Summary => $this->summary->execute($viewer, $scope()),
            StatisticsSection::Activity => $this->activity->execute($viewer, $scope()),
            StatisticsSection::Taste => $this->taste->execute($viewer, $scope()),
            StatisticsSection::Origins => $this->origins->execute($viewer, $scope()),
            StatisticsSection::Creators => $this->creators->execute($scope()),
            StatisticsSection::Ratings => $this->ratings->execute($viewer, $scope()),
            StatisticsSection::Records => $this->records->execute($viewer, $scope()),
        });
    }
}

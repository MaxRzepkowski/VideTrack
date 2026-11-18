<?php

namespace App\Http\Controllers;

use App\Accounts\UserCalendar;
use App\Actions\Users\BuildStatisticsActivity;
use App\Actions\Users\BuildStatisticsCreators;
use App\Actions\Users\BuildStatisticsOrigins;
use App\Actions\Users\BuildStatisticsPage;
use App\Actions\Users\BuildStatisticsRatings;
use App\Actions\Users\BuildStatisticsRecords;
use App\Actions\Users\BuildStatisticsTaste;
use App\Http\Data\StatisticsActivity;
use App\Http\Data\StatisticsCreators;
use App\Http\Data\StatisticsOrigins;
use App\Http\Data\StatisticsPage;
use App\Http\Data\StatisticsRatings;
use App\Http\Data\StatisticsRecords;
use App\Http\Data\StatisticsTaste;
use App\Http\Requests\StatisticsRequest;
use App\Models\User;
use App\Users\Statistics\StatisticsCache;
use App\Users\Statistics\StatisticsScope;
use App\Users\Statistics\UserHistory;
use Closure;
use Illuminate\Container\Attributes\CurrentUser;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The viewer's statistics: what they finished in a year, a range of days or
 * the whole history, for every type or one, from their own history only.
 * The top comes with the page; each section below it follows on its own.
 * Every section is kept once worked out (StatisticsCache), so a view seen
 * before reads no history at all.
 */
class StatisticsController extends Controller
{
    public function __invoke(
        #[CurrentUser] User $viewer,
        StatisticsRequest $request,
        UserHistory $history,
        BuildStatisticsPage $page,
        BuildStatisticsActivity $activity,
        BuildStatisticsTaste $taste,
        BuildStatisticsOrigins $origins,
        BuildStatisticsCreators $creators,
        BuildStatisticsRatings $ratings,
        BuildStatisticsRecords $records,
        StatisticsCache $cache,
    ): Response {
        $today = UserCalendar::of($viewer)->today();
        $period = $request->period($today->year);
        $type = $request->type();

        // The history is read once per request, and only when a section was not kept.
        $scope = null;
        $read = function () use (&$scope, $history, $viewer, $period, $type): StatisticsScope {
            return $scope ??= $history->scope($viewer, $period, $type);
        };
        $kept = fn (string $section, Closure $build): array => $cache->remember($viewer, $period, $type, $today->toDateString(), $section, $build);

        return Inertia::render('Statistics', [
            // A closure, so the follow-up request for a deferred section does not build the top again.
            'page' => fn (): array => $kept('page', fn (): StatisticsPage => $page->execute($viewer, $read())),
            'activity' => Inertia::defer(fn (): array => $kept('activity', fn (): StatisticsActivity => $activity->execute($viewer, $read()))),
            'taste' => Inertia::defer(fn (): array => $kept('taste', fn (): StatisticsTaste => $taste->execute($viewer, $read()))),
            'origins' => Inertia::defer(fn (): array => $kept('origins', fn (): StatisticsOrigins => $origins->execute($viewer, $read()))),
            'creators' => Inertia::defer(fn (): array => $kept('creators', fn (): StatisticsCreators => $creators->execute($read()))),
            'ratings' => Inertia::defer(fn (): array => $kept('ratings', fn (): StatisticsRatings => $ratings->execute($viewer, $read()))),
            'records' => Inertia::defer(fn (): array => $kept('records', fn (): StatisticsRecords => $records->execute($viewer, $read()))),
        ]);
    }
}

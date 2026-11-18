<?php

namespace App\Http\Controllers;

use App\Accounts\UserCalendar;
use App\Actions\Users\BuildStatisticsActivity;
use App\Actions\Users\BuildStatisticsPage;
use App\Actions\Users\BuildStatisticsTaste;
use App\Http\Data\StatisticsActivity;
use App\Http\Data\StatisticsPage;
use App\Http\Data\StatisticsTaste;
use App\Http\Requests\StatisticsRequest;
use App\Models\User;
use App\Users\Statistics\UserHistory;
use Illuminate\Container\Attributes\CurrentUser;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The viewer's statistics: what they finished in a year, a range of days or
 * the whole history, for every type or one, from their own history only.
 * The top comes with the page; each section below it follows on its own.
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
    ): Response {
        $scope = $history->scope($viewer, $request->period(UserCalendar::of($viewer)->today()->year), $request->type());

        return Inertia::render('Statistics', [
            // A closure, so the follow-up request for a deferred section does not build the top again.
            'page' => fn (): StatisticsPage => $page->execute($viewer, $scope),
            'activity' => Inertia::defer(fn (): StatisticsActivity => $activity->execute($viewer, $scope)),
            'taste' => Inertia::defer(fn (): StatisticsTaste => $taste->execute($viewer, $scope)),
        ]);
    }
}

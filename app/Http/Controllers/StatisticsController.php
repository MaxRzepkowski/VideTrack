<?php

namespace App\Http\Controllers;

use App\Accounts\UserCalendar;
use App\Http\Requests\StatisticsRequest;
use App\Models\User;
use App\Users\Statistics\StatisticsScope;
use App\Users\Statistics\StatisticsSection;
use App\Users\Statistics\StatisticsSections;
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
    public function __invoke(#[CurrentUser] User $viewer, StatisticsRequest $request, UserHistory $history, StatisticsSections $sections): Response
    {
        $period = $request->period(UserCalendar::of($viewer)->today()->year);
        $type = $request->type();

        // The history is read once per request, and only when a section was not kept.
        $scope = null;
        $read = function () use (&$scope, $history, $viewer, $period, $type): StatisticsScope {
            return $scope ??= $history->scope($viewer, $period, $type);
        };
        $kept = fn (StatisticsSection $section): array => $sections->of($viewer, $period, $type, $section, $read);

        return Inertia::render('Statistics', [
            // A closure, so the follow-up request for a deferred section does not build the top again.
            'page' => fn (): array => $kept(StatisticsSection::Summary),
            'activity' => Inertia::defer(fn (): array => $kept(StatisticsSection::Activity)),
            'taste' => Inertia::defer(fn (): array => $kept(StatisticsSection::Taste)),
            'origins' => Inertia::defer(fn (): array => $kept(StatisticsSection::Origins)),
            'creators' => Inertia::defer(fn (): array => $kept(StatisticsSection::Creators)),
            'ratings' => Inertia::defer(fn (): array => $kept(StatisticsSection::Ratings)),
            'records' => Inertia::defer(fn (): array => $kept(StatisticsSection::Records)),
        ]);
    }
}

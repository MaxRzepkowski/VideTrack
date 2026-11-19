<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StatisticsRequest;
use App\Models\User;
use App\Users\Statistics\PeriodKind;
use App\Users\Statistics\StatisticsScope;
use App\Users\Statistics\StatisticsSection;
use App\Users\Statistics\StatisticsSections;
use App\Users\Statistics\UserHistory;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;

/**
 * The user's statistics for a period, from their own history only, a part at
 * a time as the statistics page shows them, kept as the page keeps them. Two
 * periods are compared by asking for each.
 */
class StatisticsController extends Controller
{
    /**
     * The top of the statistics page: the time the period took, its counts
     * per type, how its titles were rated and what became of them, its first
     * and last finished title.
     *
     * @response array{data: array<string, mixed>, meta: array{period: array{kind: PeriodKind, from: string|null, to: string|null}, type: string|null}}
     */
    public function index(#[CurrentUser] User $viewer, StatisticsRequest $request, UserHistory $history, StatisticsSections $sections): JsonResponse
    {
        return $this->section($viewer, $request, $history, $sections, StatisticsSection::Summary);
    }

    /**
     * One section of the statistics page: `activity` (when things were
     * finished), `taste` (where the time went, release years, genres,
     * lengths), `origins` (countries, languages, networks or studios),
     * `creators` (cast, directors, writers, authors), `ratings` (against
     * everyone else's) or `records` (streak, busiest day, marathon); `summary`
     * is the top, the same as the statistics without a section.
     *
     * @response array{data: array<string, mixed>, meta: array{period: array{kind: PeriodKind, from: string|null, to: string|null}, type: string|null}}
     */
    public function show(#[CurrentUser] User $viewer, StatisticsRequest $request, UserHistory $history, StatisticsSections $sections, StatisticsSection $section): JsonResponse
    {
        return $this->section($viewer, $request, $history, $sections, $section);
    }

    private function section(User $viewer, StatisticsRequest $request, UserHistory $history, StatisticsSections $sections, StatisticsSection $section): JsonResponse
    {
        $period = $request->period();
        $type = $request->type();

        return response()->json([
            'data' => $sections->of($viewer, $period, $type, $section, fn (): StatisticsScope => $history->scope($viewer, $period, $type)),
            'meta' => ['period' => $period, 'type' => $type],
        ]);
    }
}

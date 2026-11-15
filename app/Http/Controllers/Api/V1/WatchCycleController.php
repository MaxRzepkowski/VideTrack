<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Library\CancelRewatch;
use App\Actions\Library\StartRewatch;
use App\Exceptions\CannotStartRewatch;
use App\Exceptions\NoRewatchToCancel;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\WatchCyclesRequest;
use App\Http\Responses\PositionWrites;
use App\Http\Responses\PositionWritten;
use App\Library\Data\WatchCycleSummary;
use App\Library\WatchCycleSummaryResolver;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;

/**
 * Viewing cycles of a show: the runs through it, the current one last.
 * Starting one is a fact ("I began again today") — nothing is deleted, the
 * counters start from zero; cancelling the newest deletes the plays it
 * collected and the run before it stands as it did.
 */
class WatchCycleController extends Controller
{
    public function index(
        #[CurrentUser] User $viewer,
        WatchCyclesRequest $request,
        WatchCycleSummaryResolver $summaries,
    ): JsonResponse {
        $root = $request->storedItem();

        return response()->json([
            'data' => $root === null ? [WatchCycleSummary::untouched()] : $summaries->resolve($viewer, $root),
        ]);
    }

    public function store(
        #[CurrentUser] User $viewer,
        WatchCyclesRequest $request,
        StartRewatch $start,
        PositionWrites $written,
    ): PositionWritten {
        $root = $request->storedItem()
            ?? throw new CannotStartRewatch('No root for this type and ref.');

        $start->execute($viewer, $root);

        return $written->of($viewer, $root, status: 201);
    }

    public function destroy(
        #[CurrentUser] User $viewer,
        WatchCyclesRequest $request,
        CancelRewatch $cancel,
        PositionWrites $written,
    ): PositionWritten {
        $root = $request->storedItem()
            ?? throw new NoRewatchToCancel('No root for this type and ref.');

        $deleted = $cancel->execute($viewer, $root);

        return $written->of($viewer, $root, ['deleted' => $deleted]);
    }
}

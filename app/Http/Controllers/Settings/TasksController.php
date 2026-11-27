<?php

namespace App\Http\Controllers\Settings;

use App\Actions\BuildTasksPage;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Tasks\ScheduledTasks;
use App\Tasks\TaskName;
use App\Tasks\TaskRunHistory;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The "Tasks" settings section: a user's recent task runs. An admin's are a
 * tab of the Instance page, with the system runs (user_id = null) and the
 * recurring schedule; per-user runs belong to their owner.
 */
class TasksController extends Controller
{
    /** An admin's tasks live on the Instance page, beside the rest of the instance. */
    public function index(#[CurrentUser] User $viewer, BuildTasksPage $page): Response|RedirectResponse
    {
        if ($viewer->isAdmin()) {
            return to_route('settings.instance.tasks');
        }

        return Inertia::render('settings/Tasks', $page->execute($viewer));
    }

    /**
     * The runs again, as plain JSON, while one is under way: the page asks
     * with `fetch`, never with `router`, so nothing repaints under the user.
     */
    public function runs(#[CurrentUser] User $viewer, TaskRunHistory $history): JsonResponse
    {
        return response()->json(['runs' => $history->recent($viewer), 'canClear' => $history->hasFinished($viewer)]);
    }

    /**
     * Runs a scheduled task now (admin only), so an operator doesn't have to
     * wait for the next cron window. Skipped when a run is already queued or in
     * progress for that task.
     */
    public function run(#[CurrentUser] User $viewer, Request $request): RedirectResponse
    {
        abort_unless($viewer->isAdmin(), 403);

        $name = TaskName::tryFrom((string) $request->string('name'));
        $task = $name === null ? null : ScheduledTasks::find($name);

        abort_if($task === null, 404);

        $task->runNow();

        return back();
    }

    /**
     * Prunes the finished (success/failure) runs the caller can see; in-flight
     * runs (pending/started) are kept so an import in progress is never lost.
     */
    public function destroy(#[CurrentUser] User $viewer, TaskRunHistory $history): RedirectResponse
    {
        $history->clearFinished($viewer);

        return back();
    }
}

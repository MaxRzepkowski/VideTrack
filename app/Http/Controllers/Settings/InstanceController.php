<?php

namespace App\Http\Controllers\Settings;

use App\Actions\BuildInstanceOverview;
use App\Actions\BuildMaintenanceChecks;
use App\Actions\BuildTasksPage;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Tasks\Maintenance\MaintenanceChecks;
use App\Tasks\Maintenance\MaintenanceRegistry;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The admin's "Instance" page, one tab per address: what needs their
 * attention, the background tasks, and the maintenance checks.
 */
class InstanceController extends Controller
{
    public function overview(BuildInstanceOverview $overview): Response
    {
        return Inertia::render('settings/instance/Overview', $overview->execute());
    }

    public function tasks(#[CurrentUser] User $viewer, BuildTasksPage $page): Response
    {
        return Inertia::render('settings/instance/Tasks', $page->execute($viewer));
    }

    public function maintenance(BuildMaintenanceChecks $checks): Response
    {
        return Inertia::render('settings/instance/Maintenance', ['checks' => $checks->execute()]);
    }

    /**
     * The checks again, as plain JSON, while one is under way: the page asks
     * with `fetch`, never with `router`, so nothing repaints under the admin.
     */
    public function checks(BuildMaintenanceChecks $checks): JsonResponse
    {
        return response()->json(['checks' => $checks->execute()]);
    }

    /** Runs a check now, so the admin need not wait for its window; one already queued or at work is left to finish. */
    public function runCheck(Request $request, MaintenanceRegistry $registry, MaintenanceChecks $checks): RedirectResponse
    {
        $code = (string) $request->string('code');

        abort_unless(array_key_exists($code, $registry->applicable()), 404);

        $checks->runNow($code);

        return back();
    }
}

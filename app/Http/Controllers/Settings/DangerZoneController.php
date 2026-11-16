<?php

namespace App\Http\Controllers\Settings;

use App\Actions\Library\ClearUserData;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\ConfirmAccountRequest;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The "Danger zone" settings section: clearing all
 * profile data and deleting the account. Both are confirmed (ConfirmAccountRequest);
 * account deletion itself stays in ProfileController::destroy.
 */
class DangerZoneController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('settings/DangerZone');
    }

    public function clearData(#[CurrentUser] User $viewer, ConfirmAccountRequest $request, ClearUserData $clear): RedirectResponse
    {
        $clear->execute($viewer);

        return back();
    }
}

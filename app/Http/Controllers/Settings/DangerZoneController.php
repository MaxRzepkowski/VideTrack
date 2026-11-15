<?php

namespace App\Http\Controllers\Settings;

use App\Actions\Library\ClearUserData;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The "Danger zone" settings section: clearing all
 * profile data and deleting the account. Both are password-confirmed;
 * account deletion itself stays in ProfileController::destroy.
 */
class DangerZoneController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('settings/DangerZone');
    }

    public function clearData(#[CurrentUser] User $viewer, Request $request, ClearUserData $clear): RedirectResponse
    {
        $request->validate([
            'password' => ['required', 'current_password'],
        ]);

        $clear->execute($viewer);

        return back();
    }
}

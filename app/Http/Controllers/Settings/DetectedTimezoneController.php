<?php

namespace App\Http\Controllers\Settings;

use App\Actions\Users\UpdatePreferences;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Records the timezone the client resolved "Automatic" to, so queued work —
 * the daily digest above all — knows it too. See UserPreferences::effectiveTimezone().
 *
 * Deliberately not part of PreferencesController::update(): this is reported
 * in the background, and that endpoint answers with a redirect, which Inertia
 * follows into a full re-render of whatever page the user happens to be on.
 * An empty 204 leaves the open page exactly as it was.
 */
class DetectedTimezoneController extends Controller
{
    public function __invoke(#[CurrentUser] User $viewer, Request $request, UpdatePreferences $preferences): Response
    {
        $request->validate([
            'detected_timezone' => ['required', 'string', 'timezone:all'],
        ]);

        // Reporting is not choosing: `timezone` stays untouched, so a user on
        // "Automatic" keeps it, and an explicit preference keeps winning.
        $preferences->execute($viewer, ['detected_timezone' => $request->string('detected_timezone')->value()]);

        return response()->noContent();
    }
}

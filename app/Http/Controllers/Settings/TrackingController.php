<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Inertia\Response;

class TrackingController extends Controller
{
    /**
     * The tracking matrix (sources × calendar / countdowns / notifications)
     * plus per-consumer display options. Saved through preferences.update —
     * every value lives in the shared `auth.user.preferences` blob.
     */
    public function edit(): Response
    {
        return Inertia::render('settings/Tracking');
    }
}

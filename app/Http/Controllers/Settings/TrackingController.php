<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Tracking\Settings\TypePanels;
use Inertia\Inertia;
use Inertia\Response;

class TrackingController extends Controller
{
    /**
     * The tracking matrix (sources × calendar / countdowns / notifications)
     * and what each kind of title adds to it. Saved through
     * preferences.update — every value lives in the shared
     * `auth.user.preferences` blob.
     */
    public function edit(TypePanels $panels): Response
    {
        return Inertia::render('settings/Tracking', ['types' => $panels->all()]);
    }
}

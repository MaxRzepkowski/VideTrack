<?php

namespace App\Http\Controllers\Settings;

use App\Actions\Users\IssueCalendarToken;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;

/**
 * A new address for the user's calendar feeds: a leaked one stops working at
 * once, and every subscription made with it.
 */
class CalendarTokenController extends Controller
{
    public function __invoke(#[CurrentUser] User $viewer, IssueCalendarToken $issueToken): RedirectResponse
    {
        $issueToken->execute($viewer);

        return back();
    }
}

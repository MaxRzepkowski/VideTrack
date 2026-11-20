<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Data\SubscriptionFeed;
use App\Models\User;
use App\Tracking\Surfaces\IcsFeed;
use App\Tracking\Surfaces\RssFeed;
use Illuminate\Container\Attributes\CurrentUser;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The "Calendar subscription" settings section: the addresses a calendar app
 * and a feed reader subscribe to, each with the scope it carries by default.
 */
class CalendarSubscriptionController extends Controller
{
    public function edit(#[CurrentUser] User $viewer): Response
    {
        $token = $viewer->calendarToken();

        return Inertia::render('settings/CalendarSubscription', [
            'calendarFeed' => new SubscriptionFeed(route('calendar.feed', ['token' => $token]), IcsFeed::DEFAULT_SCOPE),
            'rssFeed' => new SubscriptionFeed(route('calendar.rss', ['token' => $token]), RssFeed::DEFAULT_SCOPE),
        ]);
    }
}

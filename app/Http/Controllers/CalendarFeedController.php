<?php

namespace App\Http\Controllers;

use App\Accounts\UserCalendar;
use App\Models\User;
use App\Tracking\Surfaces\IcsFeed;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;

/**
 * Public iCal feed of a user's release calendar, addressed by an
 * opaque token so calendar apps can subscribe (webcal://) or download the .ics
 * without a session. Same sources as the on-site calendar, over a wide window.
 */
class CalendarFeedController extends Controller
{
    public function __invoke(string $token, IcsFeed $feed): Response
    {
        $user = User::query()->withCalendarToken($token)->firstOrFail();

        // The feed is public and polled repeatedly by calendar apps, and each
        // build fans a ~395-day window across every source. Cache the rendered
        // body per user for an hour (the feed already advertises a 12h refresh),
        // keyed by the user's day so it rolls over at their midnight, as its window does.
        $body = Cache::remember(
            "calendar-feed:{$user->id}:".UserCalendar::of($user)->today()->toDateString(),
            now()->addHour(),
            fn (): string => $feed->render($user),
        );

        return response($body, 200, [
            'Content-Type' => 'text/calendar; charset=utf-8',
            'Content-Disposition' => 'inline; filename="videtrack.ics"',
        ]);
    }
}

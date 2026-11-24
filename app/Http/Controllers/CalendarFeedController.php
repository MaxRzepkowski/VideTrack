<?php

namespace App\Http\Controllers;

use App\Accounts\UserCalendar;
use App\Http\Requests\FeedRequest;
use App\Models\User;
use App\Tracking\Surfaces\FeedFilter;
use App\Tracking\Surfaces\IcsFeed;
use App\Tracking\Surfaces\RssFeed;
use Closure;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;

/**
 * Public feeds of a user's release calendar — iCal for calendar apps
 * (webcal:// or a downloaded .ics), RSS for feed readers — addressed by an
 * opaque token so they need no session. Same sources as the on-site calendar;
 * the address may narrow them to a variant.
 */
class CalendarFeedController extends Controller
{
    public function ics(FeedRequest $request, string $token, IcsFeed $feed): Response
    {
        $user = $this->owner($token);
        $filter = $request->feedFilter(IcsFeed::DEFAULT_SCOPE);

        return response($this->cached('calendar-feed', $user, $filter, fn (): string => $feed->render($user, $filter)), 200, [
            'Content-Type' => 'text/calendar; charset=utf-8',
            'Content-Disposition' => 'inline; filename="videtrack.ics"',
        ]);
    }

    public function rss(FeedRequest $request, string $token, RssFeed $feed): Response
    {
        $user = $this->owner($token);
        $filter = $request->feedFilter(RssFeed::DEFAULT_SCOPE);

        return response($this->cached('calendar-rss', $user, $filter, fn (): string => $feed->render($user, $filter)), 200, [
            'Content-Type' => 'application/rss+xml; charset=utf-8',
        ]);
    }

    /** A blocked account's feeds end with its sessions and tokens. */
    private function owner(string $token): User
    {
        $user = User::query()->withCalendarToken($token)->firstOrFail();

        abort_if($user->isBlocked(), 404);

        return $user;
    }

    /**
     * The feeds are public and polled repeatedly, and each build fans a wide
     * window across every source. The body is kept for an hour (the feeds
     * advertise a 12h refresh) per user, variant and language, keyed by the
     * user's day so it rolls over at their midnight, as the window does.
     *
     * @param  Closure(): string  $render
     */
    private function cached(string $feed, User $user, FeedFilter $filter, Closure $render): string
    {
        // The settings in the key: a calendar app asks again within the hour, and a
        // source switched off on the tracking page should be gone by then.
        $settings = hash('xxh128', json_encode($user->preferences->resolved(), JSON_THROW_ON_ERROR));
        $key = implode(':', [$feed, $user->id, UserCalendar::of($user)->today()->toDateString(), $user->preferredLocale(), $filter->key(), $settings]);

        return Cache::remember($key, now()->addHour(), $render);
    }
}

<?php

use App\Actions\Library\SyncListMembership;
use App\Actions\Users\IssueCalendarToken;
use App\Models\MediaItem;
use App\Models\User;
use App\Tracking\Releases\ReleaseEntry;
use App\Tracking\Surfaces\CalendarDays;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;

/** Watchlists a factory-built show so the calendar surfaces its episodes. */
function watchlistShow(User $user, string $title, string $date, array $numbers): MediaItem
{
    $show = MediaItem::factory()->show()->create(['title' => $title]);
    $season = MediaItem::factory()->season($show, 1)->create();

    foreach ($numbers as $number) {
        MediaItem::factory()->episode($season, $number, $date)->create();
    }

    app(SyncListMembership::class)->execute($user, $show, [$user->watchlist()->id]);

    return $show;
}

test("a day's episodes are grouped by show, then episode number", function () {
    $user = User::factory()->create();
    $date = today()->addDays(3)->toDateString();

    // Bravo created before Alpha to prove it is sorted, not insertion order.
    watchlistShow($user, 'Bravo', $date, [1, 2]);
    watchlistShow($user, 'Alpha', $date, [1, 2]);

    $days = app(CalendarDays::class)->range($user, today()->toDateString(), today()->addDays(10)->toDateString());
    $day = collect($days)->firstWhere('date', $date);

    $order = array_map(fn (ReleaseEntry $e) => $e->showTitle.' E'.$e->media->number, $day->entries);

    expect($order)->toBe(['Alpha E1', 'Alpha E2', 'Bravo E1', 'Bravo E2']);
});

test('range includes past releases too', function () {
    $user = User::factory()->create();
    watchlistShow($user, 'Past Show', today()->subDays(5)->toDateString(), [1]);

    $days = app(CalendarDays::class)->range($user, today()->subDays(10)->toDateString(), today()->toDateString());

    expect(collect($days)->pluck('date'))->toContain(today()->subDays(5)->toDateString());
});

test('the calendar page renders the month with a subscription feed url', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/calendar')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Calendar')
            ->has('month')
            ->where('feedUrl', fn (string $url) => str_contains($url, $user->calendarToken()))
            ->has('webcalUrl'));
});

test('the month spans the month itself and steps by months', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/calendar?date=2026-07-15')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('anchor', '2026-07-01')
            ->where('prev', '2026-06-01')
            ->where('next', '2026-08-01')
            ->where('month.month', '2026-07-01')
            ->where('month.start', '2026-07-01')
            ->where('month.end', '2026-07-31'));
});

test('the month carries its own releases and none of a neighbouring month', function () {
    $user = User::factory()->create();

    // The view lays the days out in reading order, so 30 June has no cell in
    // July — it belongs to June's own page.
    watchlistShow($user, 'Alpha', '2026-06-30', [1]);
    watchlistShow($user, 'Bravo', '2026-07-01', [1]);

    $this->actingAs($user)->get('/calendar?date=2026-07-20')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('month.days', 1)
            ->where('month.days.0.date', '2026-07-01'));
});

test('the public ical feed serves the user releases by token, timed and all-day', function () {
    $user = User::factory()->create();

    $show = MediaItem::factory()->show()->create(['title' => 'Alpha']);
    $season = MediaItem::factory()->season($show, 1)->create();
    MediaItem::factory()->episode($season, 1, today()->addDays(4)->toDateString())->create();
    MediaItem::factory()->episode($season, 2, today()->addDays(3)->toDateString())->create()
        ->update(['release_time' => today()->addDays(3)->setTime(1, 0)]);
    app(SyncListMembership::class)->execute($user, $show, [$user->watchlist()->id]);

    $token = $user->calendarToken();

    $response = $this->get("/calendar/{$token}.ics");

    $response->assertOk();
    expect($response->headers->get('Content-Type'))->toContain('text/calendar');

    $body = $response->getContent();

    expect($body)->toContain('BEGIN:VCALENDAR')
        ->toContain('BEGIN:VEVENT')
        ->toContain('Alpha')
        // All-day episode.
        ->toContain('DTSTART;VALUE=DATE:'.today()->addDays(4)->format('Ymd'))
        // Timed episode (UTC instant).
        ->toContain('DTSTART:'.today()->addDays(3)->format('Ymd').'T010000Z')
        // UIDs keep the shape calendars already hold: a release without a
        // platform or a status keeps the plain UID.
        ->toMatch('/UID:episode-[^\\s]+--'.today()->addDays(4)->toDateString().'@videtrack/');
});

test('the ical feed 404s on an unknown token', function () {
    $this->get('/calendar/deadbeef.ics')->assertNotFound();
});

test('a newly issued feed token replaces the one before', function () {
    $user = User::factory()->create();
    $old = $user->calendarToken();

    $new = app(IssueCalendarToken::class)->execute($user);

    expect($new)->toHaveLength(48)->not->toBe($old)
        ->and($user->fresh()->calendarToken())->toBe($new);

    $this->get("/calendar/{$old}.ics")->assertNotFound();
    $this->get("/calendar/{$new}.ics")->assertOk();
});

test("the calendar opens on the viewer's own today and month", function () {
    // The last noon of October in Greenwich is the 1st of November on Kiritimati.
    $this->travelTo(CarbonImmutable::parse('2026-10-31 12:00', 'UTC'));
    $user = User::factory()->create(['preferences' => ['timezone' => 'Pacific/Kiritimati']]);

    $this->actingAs($user)->get(route('calendar'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('today', '2026-11-01')->where('anchor', '2026-11-01'));
});

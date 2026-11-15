<?php

use App\Actions\Media\RefreshEvents;
use App\Actions\Tracking\HideEvent;
use App\Catalog\Data\EventData;
use App\Catalog\Data\MediaSummary;
use App\Catalog\Events\EventStore;
use App\Models\CatalogEvent;
use App\Models\MediaItem;
use App\Models\User;
use App\Tracking\Events\VisibleEvents;
use App\Tracking\Surfaces\CalendarDays;
use App\Tracking\Surfaces\IcsFeed;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Inertia\Inertia;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * Showcases (Summer Game Fest, a Nintendo Direct): kept from the provider by a
 * daily task, on every calendar until a user takes one off, behind the "live"
 * button while on the air.
 */

test('the daily task keeps the events ahead and just past, their shown games replaced', function () {
    $this->travelBack();
    enableGamesModule();
    $since = today()->subDays(RefreshEvents::RECENT_DAYS)->getTimestamp();
    Http::fake(['*/events' => Http::response([
        [
            'id' => 6045, 'name' => 'Summer Game Fest 2026', 'description' => 'The summer showcase.',
            'start_time' => CarbonImmutable::parse('2026-10-20 19:00', 'UTC')->getTimestamp(),
            'end_time' => CarbonImmutable::parse('2026-10-20 21:00', 'UTC')->getTimestamp(),
            'time_zone' => 'America/Los_Angeles', 'live_stream_url' => 'https://www.youtube.com/watch?v=sgf',
            'event_logo' => ['image_id' => 'evlogo'],
            'games' => [['id' => 1, 'name' => 'Shown', 'cover' => ['image_id' => 'a']], ['id' => 2, 'name' => 'No cover']],
        ],
        // An end before the start is no end; a stream that is no web address is none.
        ['id' => 6046, 'name' => 'Odd', 'start_time' => 2_000_000_000, 'end_time' => 1_000, 'live_stream_url' => 'javascript:x'],
        ['id' => 6047, 'start_time' => 2_000_000_000],
    ])]);

    expect(app(RefreshEvents::class)->execute())->toBe(['events' => 2, 'failed' => []]);

    $show = CatalogEvent::query()->where('ref', '6045')->sole();
    $odd = CatalogEvent::query()->where('ref', '6046')->sole();

    expect([$show->name, $show->time_zone, $show->live_stream_url, $show->logo_path])
        ->toBe(['Summer Game Fest 2026', 'America/Los_Angeles', 'https://www.youtube.com/watch?v=sgf', 'https://images.igdb.com/igdb/image/upload/t_screenshot_big/evlogo.jpg'])
        ->and($show->ends_at?->toIso8601String())->toBe('2026-10-20T21:00:00+00:00')
        ->and(array_map(fn (MediaSummary $title): string => $title->title, app(EventStore::class)->titlesOf($show)))->toBe(['Shown'])
        ->and([$odd->ends_at, $odd->live_stream_url])->toBe([null, null]);
    Http::assertSent(fn (Request $request): bool => str_contains($request->body(), "where start_time >= {$since}"));

    // Asked again, the event's games are what the provider lists now.
    app(EventStore::class)->store('igdb', [new EventData('6045', 'Summer Game Fest 2026', CarbonImmutable::parse('2026-10-20 19:00', 'UTC'), titles: [])]);

    expect(app(EventStore::class)->titlesOf($show->refresh()))->toBe([]);
});

test('an event hidden by a user leaves their calendar, feed and live button, not anyone else', function () {
    enableGamesModule();
    $user = User::factory()->create();
    $other = User::factory()->create();
    $event = CatalogEvent::factory()->create(['starts_at' => now()->subMinutes(10), 'ends_at' => now()->addHour()]);

    $this->actingAs($user)->putJson(route('ui.events.hidden.update', ['provider' => 'igdb', 'ref' => $event->ref]))
        ->assertOk()->assertJsonPath('data.hidden', true);

    expect(app(VisibleEvents::class)->liveOrSoon($user, now()))->toHaveCount(0)
        ->and(app(VisibleEvents::class)->liveOrSoon($other, now()))->toHaveCount(1);

    $this->actingAs($user)->deleteJson(route('ui.events.hidden.destroy', ['provider' => 'igdb', 'ref' => $event->ref]))
        ->assertOk()->assertJsonPath('data.hidden', false);

    expect(app(VisibleEvents::class)->liveOrSoon($user, now()))->toHaveCount(1);
    $this->actingAs($user)->putJson(route('ui.events.hidden.update', ['provider' => 'igdb', 'ref' => '404404']))->assertNotFound();
});

test('the live button is told of the events on the air or starting within hours, a show without an end running its usual length', function () {
    enableGamesModule();
    $user = User::factory()->create();
    $lookahead = config()->integer('tracker.events.live_lookahead_hours');
    $fallback = config()->integer('tracker.events.fallback_minutes');
    CatalogEvent::factory()->create(['name' => 'On now', 'starts_at' => now()->subHour(), 'ends_at' => now()->addHour()]);
    CatalogEvent::factory()->create(['name' => 'Soon', 'starts_at' => now()->addHours($lookahead - 1), 'ends_at' => null]);
    CatalogEvent::factory()->create(['name' => 'Open-ended, still on', 'starts_at' => now()->subMinutes($fallback - 5), 'ends_at' => null]);
    CatalogEvent::factory()->create(['name' => 'Open-ended, over', 'starts_at' => now()->subMinutes($fallback + 5), 'ends_at' => null]);
    CatalogEvent::factory()->create(['name' => 'Over', 'starts_at' => now()->subHours(3), 'ends_at' => now()->subHour()]);
    CatalogEvent::factory()->create(['name' => 'Tomorrow evening', 'starts_at' => now()->addHours($lookahead + 1)]);

    $this->actingAs($user)->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('liveEvents', fn ($events): bool => collect($events)->pluck('name')->all() === ['On now', 'Open-ended, still on', 'Soon'])
            ->where('liveEvents.2.endsAt', now()->addHours($lookahead - 1)->addMinutes($fallback)->toIso8601String()));
});

test('the calendar puts an event on the day it starts in the viewer\'s zone, the dashboard keeps to releases', function () {
    enableGamesModule();
    $user = User::factory()->create(['preferences' => ['timezone' => 'Europe/Warsaw']]);
    // 23:30 UTC on the 14th is the 15th in Warsaw.
    CatalogEvent::factory()->create(['name' => 'Late show', 'starts_at' => CarbonImmutable::parse('2026-10-14 23:30', 'UTC'), 'ends_at' => null]);

    $days = app(CalendarDays::class)->range($user, '2026-10-01', '2026-10-31');

    expect(array_map(fn ($day): array => [$day->date, array_map(fn ($item): string => $item->event->name, $day->events), count($day->entries)], $days))
        ->toBe([['2026-10-15', ['Late show'], 0]])
        ->and(app(CalendarDays::class)->nearest($user))->toBe([]);
});

test("a month on the calendar runs from the viewer's midnight to theirs", function () {
    enableGamesModule();
    $user = User::factory()->create(['preferences' => ['timezone' => 'Europe/Warsaw']]);
    // Half past midnight in Warsaw: on the 1st of October, and of November.
    CatalogEvent::factory()->create(['name' => 'First night', 'starts_at' => CarbonImmutable::parse('2026-09-30 22:30', 'UTC'), 'ends_at' => null]);
    CatalogEvent::factory()->create(['name' => 'Next month', 'starts_at' => CarbonImmutable::parse('2026-10-31 23:30', 'UTC'), 'ends_at' => null]);

    $days = app(CalendarDays::class)->range($user, '2026-10-01', '2026-10-31');

    expect(array_map(fn ($day): array => [$day->date, array_map(fn ($item): string => $item->event->name, $day->events)], $days))
        ->toBe([['2026-10-01', ['First night']]]);
});

test('an event on the calendar is marked by the medium its provider serves', function () {
    enableGamesModule();
    CatalogEvent::factory()->create(['starts_at' => CarbonImmutable::parse('2026-10-14 18:00', 'UTC'), 'ends_at' => null]);

    expect(app(CalendarDays::class)->range(User::factory()->create(), '2026-10-01', '2026-10-31')[0]->events[0]->mediumType)->toBe('game');
});

test('an event whose provider serves no medium here is on no calendar, countdown or live button', function () {
    $user = User::factory()->create();
    CatalogEvent::factory()->create(['starts_at' => now()->subHour(), 'ends_at' => now()->addHour()]);
    CatalogEvent::factory()->create(['starts_at' => CarbonImmutable::parse('2026-10-14 18:00', 'UTC'), 'ends_at' => null]);

    expect(app(CalendarDays::class)->range($user, '2026-10-01', '2026-10-31'))->toBe([]);

    $this->actingAs($user)->get(route('countdowns'))
        ->assertInertia(fn (Assert $page) => $page->where('liveEvents', [])->where('entries', []));
});

test('the iCal feed carries an event as a timed entry with its stream', function () {
    enableGamesModule();
    $user = User::factory()->create();
    CatalogEvent::factory()->create(['ref' => '6045', 'name' => 'Summer Game Fest', 'starts_at' => now()->addDays(2), 'ends_at' => now()->addDays(2)->addHours(2)]);

    $ics = app(IcsFeed::class)->render($user);

    expect($ics)->toContain('SUMMARY:Summer Game Fest')
        ->toContain('UID:event-igdb-6045@videtrack')
        ->toContain('URL:https://www.youtube.com/watch?v=sgf');
});

test("an event's page shows when it runs, what it showed and whether it is hidden", function () {
    enableGamesModule();
    $user = User::factory()->create();
    $event = CatalogEvent::factory()->create(['ref' => '6045', 'name' => 'Summer Game Fest 2026']);
    app(EventStore::class)->store('igdb', [new EventData('6045', 'Summer Game Fest 2026', $event->starts_at->toImmutable(), titles: [
        new MediaSummary(type: 'game', ref: '7346', provider: 'igdb', title: 'The Legend of Zelda: Breath of the Wild'),
    ])]);

    $this->actingAs($user)->get(route('events.show', ['provider' => 'igdb', 'ref' => '6045']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('media/EventPage')
            ->where('page.event.name', 'Summer Game Fest 2026')
            ->where('page.hidden', false)
            ->where('page.titles.0.ref', '7346')
            ->has('userStates.game:7346'));
    Http::assertNothingSent();
});

test('an event long past, not kept, is read from its provider and is on no calendar to be taken off', function () {
    $this->travelBack();
    enableGamesModule();
    Http::fake(['*/events' => Http::response([[
        'id' => 907, 'name' => 'The Game Awards 2025', 'start_time' => CarbonImmutable::parse('2025-12-12 00:30', 'UTC')->getTimestamp(),
        'games' => [['id' => 7346, 'name' => 'Shown', 'cover' => ['image_id' => 'a']]],
    ]])]);

    $this->actingAs(User::factory()->create())->get(route('events.show', ['provider' => 'igdb', 'ref' => '907']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('page.event.name', 'The Game Awards 2025')
            ->where('page.event.endsAt', CarbonImmutable::parse('2025-12-12 00:30', 'UTC')->addMinutes(config()->integer('tracker.events.fallback_minutes'))->toIso8601String())
            ->where('page.hidden', null)
            ->where('page.titles.0.ref', '7346'));
    Http::assertSent(fn (Request $request): bool => str_contains($request->body(), 'where id = 907;'));
});

test('an event its provider does not know is not found', function () {
    $this->travelBack();
    enableGamesModule();
    Http::fake(['*/events' => Http::response([])]);

    $this->actingAs(User::factory()->create())->get(route('events.show', ['provider' => 'igdb', 'ref' => '1']))->assertNotFound();
    $this->actingAs(User::factory()->create())->get(route('events.show', ['provider' => 'nope', 'ref' => '1']))->assertNotFound();
});

test("a game's page names every event that showed it, asked of its provider after the page is drawn", function () {
    $this->travelBack();
    enableGamesModule();
    Http::fake([
        '*/events' => Http::response([
            ['id' => 907, 'name' => 'The Game Awards 2025', 'start_time' => CarbonImmutable::parse('2025-12-12 00:30', 'UTC')->getTimestamp(), 'event_logo' => ['image_id' => 'tga']],
            ['id' => 702, 'name' => 'The Game Awards 2024', 'start_time' => CarbonImmutable::parse('2024-12-13 00:30', 'UTC')->getTimestamp()],
        ]),
        '*/multiquery' => Http::response(igdbGamePage(jsonFixture('igdb/game'))),
    ]);
    $user = User::factory()->create();
    MediaItem::factory()->game()->create(['provider_ref' => '7346']);

    $this->actingAs($user)->get(route('title.details', ['type' => 'game', 'ref' => '7346']))->assertInertia(fn (Assert $page) => $page->missing('shownAt'));
    $this->actingAs($user)->get(route('title.details', ['type' => 'game', 'ref' => '7346']), [
        'X-Inertia' => 'true',
        'X-Inertia-Version' => Inertia::getVersion(),
        'X-Inertia-Partial-Component' => 'media/GameDetails',
        'X-Inertia-Partial-Data' => 'shownAt',
    ])
        ->assertJsonPath('props.shownAt.0.name', 'The Game Awards 2025')
        ->assertJsonPath('props.shownAt.0.logoPath', 'https://images.igdb.com/igdb/image/upload/t_screenshot_big/tga.jpg')
        ->assertJsonPath('props.shownAt.1.ref', '702');
    Http::assertSent(fn (Request $request): bool => str_contains($request->body(), 'where games = (7346); sort start_time desc;'));
});

test('countdowns count down to the events among the premieres until each show is over, an option of their own in the type filter', function () {
    enableGamesModule();
    $user = User::factory()->create();
    $fallback = config()->integer('tracker.events.fallback_minutes');
    CatalogEvent::factory()->create(['name' => 'On now', 'starts_at' => now()->subHour(), 'ends_at' => now()->addHour()]);
    CatalogEvent::factory()->create(['name' => 'Open-ended, over', 'starts_at' => now()->subMinutes($fallback + 5), 'ends_at' => null]);
    CatalogEvent::factory()->create(['name' => 'Next week', 'starts_at' => now()->addWeek(), 'ends_at' => null]);
    $hidden = CatalogEvent::factory()->create(['name' => 'Hidden', 'starts_at' => now()->addDay()]);
    app(HideEvent::class)->execute($user, $hidden);

    $this->actingAs($user)->get(route('countdowns'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('entries', fn ($entries): bool => collect($entries)->pluck('event.name')->all() === ['On now', 'Next week'])
            ->where('entries.0.mediumType', 'game')
            ->where('types', fn ($types): bool => collect($types)->contains(fn ($type): bool => $type['type'] === 'event' && $type['count'] === 2)));

    $this->actingAs($user)->get(route('countdowns', ['type' => 'event', 'q' => 'next']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('filters.type', 'event')
            ->where('entries', fn ($entries): bool => collect($entries)->pluck('event.name')->all() === ['Next week']));
});

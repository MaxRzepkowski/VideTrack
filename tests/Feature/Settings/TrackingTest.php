<?php

use App\Catalog\MediaTypeRegistry;
use App\Catalog\ProviderRegistry;
use App\Enums\ReleaseDateMode;
use App\Models\User;
use App\Tracking\Settings\TypePanels;
use App\Tracking\Surface;
use App\Tracking\TrackingSource;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * @return array<string, list<array{key: string, preferences: array<string, string>}>> type => its options, each with surface => preference
 */
function trackingPanels(): array
{
    $page = test()->actingAs(User::factory()->create())->get(route('settings.tracking.edit'))->assertOk();
    $panels = [];

    $page->assertInertia(function (Assert $page) use (&$panels): void {
        $page->component('settings/Tracking');

        foreach ($page->toArray()['props']['types'] as $panel) {
            $panels[$panel['type']] = array_map(fn (array $option): array => [
                'key' => $option['key'],
                'preferences' => array_column($option['switches'], 'preference', 'surface'),
            ], $panel['options']);
        }
    });

    return $panels;
}

test('every kind of title comes with the settings the code reading them declares', function () {
    enableGamesModule();

    $panels = trackingPanels();

    expect(array_keys($panels))->toBe(['show', 'movie', 'game'])
        ->and($panels['show'])->toBe([
            ['key' => 'episodes', 'preferences' => ['notifications' => 'notify_episodes']],
            ['key' => 'status', 'preferences' => ['notifications' => 'notify_show_status']],
        ])
        ->and(array_column($panels['movie'], 'key'))->toBe(['premiere', 'home'])
        ->and($panels['movie'][0]['preferences'])->toBe([
            'calendar' => 'calendar_release_mode',
            'countdowns' => 'countdown_release_mode',
            'notifications' => 'notifications_release_mode',
        ])
        ->and($panels['game'])->toBe([
            ['key' => 'platforms', 'preferences' => ['calendar' => 'calendar_game_platforms', 'countdowns' => 'countdown_game_platforms']],
            ['key' => 'events', 'preferences' => ['calendar' => 'calendar_events', 'countdowns' => 'countdown_events']],
        ]);
});

test('a setting a kind of title declares saves only as a value its options read', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->from(route('settings.tracking.edit'))
        ->patch(route('settings.preferences.update'), ['countdown_release_mode' => ReleaseDateMode::Digital->value, 'notify_show_status' => '0'])
        ->assertSessionHasNoErrors();

    expect($user->refresh()->preferences->releaseMode(Surface::Countdowns))->toBe(ReleaseDateMode::Digital)
        ->and($user->preferences->toStored()['notify_show_status'])->toBeFalse();

    $this->patch(route('settings.preferences.update'), ['calendar_release_mode' => 'never', 'notify_episodes' => 'often'])
        ->assertSessionHasErrors(['calendar_release_mode', 'notify_episodes']);
});

// The front's test factory reads the same file, so its panels are the server's.
test('the page sends the panels as the front tests know them, every module on', function () {
    enableGamesModule();
    enableBooksModule();
    enableMangaModule();
    enableComicsModule();

    $this->actingAs(User::factory()->create())
        ->get(route('settings.tracking.edit'))
        ->assertInertia(fn (Assert $page) => $page->where('types', jsonFixture('tracking/type-panels')));
});

test("a kind of title's own sources save whole, the views together", function () {
    $user = User::factory()->create(['preferences' => ['type_sources' => ['show' => ['notifications' => ['followed']]]]]);
    $own = ['movie' => ['calendar' => ['backlog', 'activity'], 'countdowns' => [], 'notifications' => ['followed']]];

    $this->actingAs($user)
        ->from(route('settings.tracking.edit'))
        ->patch(route('settings.preferences.update'), ['type_sources' => $own])
        ->assertSessionHasNoErrors();

    expect($user->refresh()->preferences->toStored()['type_sources'])->toBe($own)
        ->and($user->preferences->matrix(Surface::Calendar, 'movie'))->toBe([TrackingSource::Backlog, TrackingSource::Activity])
        ->and($user->preferences->matrix(Surface::Countdowns, 'movie'))->toBe([])
        ->and($user->preferences->matrix(Surface::Notifications, 'show'))->toBe($user->preferences->matrix(Surface::Notifications));
});

test('own sources name a kind of title, a surface and rows that exist, and the views come together', function (array $own, string $error) {
    $this->actingAs(User::factory()->create())
        ->patch(route('settings.preferences.update'), ['type_sources' => $own])
        ->assertSessionHasErrors($error);
})->with([
    'an unknown kind of title' => [['boardgame' => ['notifications' => []]], 'type_sources'],
    'an unknown surface' => [['movie' => ['digest' => []]], 'type_sources.movie'],
    'an unknown row' => [['movie' => ['notifications' => ['someday']]], 'type_sources.movie.notifications.0'],
    'rows under names' => [['movie' => ['notifications' => ['a' => 'backlog']]], 'type_sources.movie.notifications'],
    'a surface that is not a list' => [['movie' => ['notifications' => 'backlog']], 'type_sources.movie.notifications'],
    'the countdowns without the calendar' => [['movie' => ['countdowns' => []]], 'type_sources.movie.calendar'],
    'the views split across two kinds, the films' => [['movie' => ['calendar' => []], 'show' => ['countdowns' => []]], 'type_sources.movie.countdowns'],
    'the views split across two kinds, the shows' => [['movie' => ['calendar' => []], 'show' => ['countdowns' => []]], 'type_sources.show.calendar'],
    'more rows than there are' => [['movie' => ['notifications' => array_fill(0, count(TrackingSource::cases()) + 1, 'backlog')]], 'type_sources.movie.notifications'],
    'the calendar without the countdowns' => [['movie' => ['calendar' => ['backlog']]], 'type_sources.movie.countdowns'],
]);

test("a film's release mode saves as neither of its dates", function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->patch(route('settings.preferences.update'), ['calendar_release_mode' => ReleaseDateMode::None->value])
        ->assertSessionHasNoErrors();

    expect($user->refresh()->preferences->releaseMode(Surface::Calendar))->toBe(ReleaseDateMode::None);
});

test('the own sources are sent whole: a kind of title left out follows the defaults again', function () {
    $user = User::factory()->create(['preferences' => ['type_sources' => [
        'movie' => ['notifications' => []],
        'show' => ['calendar' => [], 'countdowns' => []],
    ]]]);

    $this->actingAs($user)
        ->patch(route('settings.preferences.update'), ['type_sources' => ['show' => ['calendar' => [], 'countdowns' => []]]])
        ->assertSessionHasNoErrors();

    expect($user->refresh()->preferences->matrix(Surface::Notifications, 'movie'))->toBe($user->preferences->matrix(Surface::Notifications))
        ->and($user->preferences->matrix(Surface::Calendar, 'show'))->toBe([]);

    $this->patch(route('settings.preferences.update'), ['type_sources' => []])->assertSessionHasNoErrors();

    expect($user->refresh()->preferences->matrix(Surface::Calendar, 'show'))->toBe($user->preferences->matrix(Surface::Calendar));
});

test('a save without the own sources leaves them as they were', function () {
    $own = ['movie' => ['notifications' => ['followed']]];
    $user = User::factory()->create(['preferences' => ['type_sources' => $own]]);

    $this->actingAs($user)
        ->patch(route('settings.preferences.update'), ['spoiler_protection' => false])
        ->assertSessionHasNoErrors();

    expect($user->refresh()->preferences->toStored()['type_sources'])->toBe($own);
});

// Copying the defaults gives the calendar and the countdowns the same rows, and two kinds of title may well agree.
test('own rows save when lists share rows, as copying the defaults makes them', function (array $typeSources) {
    enableGamesModule();
    $user = User::factory()->create();

    $this->actingAs($user)
        ->from(route('settings.tracking.edit'))
        ->patch(route('settings.preferences.update'), ['type_sources' => $typeSources])
        ->assertSessionHasNoErrors();

    expect($user->refresh()->preferences->toStored()['type_sources'])->toBe($typeSources);
})->with([
    'the views copied from the defaults' => [['movie' => [
        'calendar' => ['activity', 'backlog', 'on_hold', 'dropped', 'followed', 'creator'],
        'countdowns' => ['activity', 'backlog', 'on_hold', 'followed', 'creator'],
    ]]],
    'the notifications copied, two kinds of title' => [[
        'movie' => ['notifications' => ['activity', 'backlog', 'followed', 'creator']],
        'show' => ['notifications' => ['activity', 'backlog', 'followed', 'creator']],
    ]],
    'one row on both views' => [['movie' => ['calendar' => ['backlog'], 'countdowns' => ['backlog']]]],
    'every kind of title on every surface' => [['show' => ['calendar' => ['activity'], 'countdowns' => ['activity'], 'notifications' => ['activity']], 'movie' => ['calendar' => ['activity'], 'countdowns' => ['activity'], 'notifications' => ['activity']], 'game' => ['calendar' => ['activity'], 'countdowns' => ['activity'], 'notifications' => ['activity']]]],
]);

test('the page saves what it shows back as it got it', function () {
    $own = ['movie' => ['calendar' => ['backlog'], 'countdowns' => ['backlog'], 'notifications' => ['backlog']]];
    $user = User::factory()->create(['preferences' => ['type_sources' => $own]]);

    $this->actingAs($user)
        ->from(route('settings.tracking.edit'))
        ->patch(route('settings.preferences.update'), ['type_sources' => json_decode((string) json_encode($user->preferences->resolved()['type_sources']), true)])
        ->assertSessionHasNoErrors();

    expect($user->refresh()->preferences->toStored()['type_sources'])->toBe($own);
});

// A setting shown on two panels would turn two kinds of title at once, and each panel would claim it.
test('no setting is switched from two kinds of title', function () {
    enableGamesModule();
    enableBooksModule();
    enableMangaModule();
    enableComicsModule();

    $owners = [];

    foreach (app(TypePanels::class)->all() as $panel) {
        foreach ($panel->options as $option) {
            foreach ($option->switches as $switch) {
                $owners[$switch->preference][$panel->type] = true;
            }
        }
    }

    expect(array_filter($owners, fn (array $types): bool => count($types) > 1))->toBe([]);
});

test('own sources of a kind whose module is off outlive saves, until the module is back and its own are sent', function () {
    $user = User::factory()->create(['preferences' => ['type_sources' => ['game' => ['notifications' => ['followed']]]]]);
    $this->actingAs($user)->from(route('settings.tracking.edit'));

    $this->patch(route('settings.preferences.update'), ['type_sources' => ['movie' => ['notifications' => []]]])->assertSessionHasNoErrors();
    $this->patch(route('settings.preferences.update'), ['type_sources' => ['movie' => ['notifications' => ['backlog']]]])->assertSessionHasNoErrors();

    expect($user->refresh()->preferences->toStored()['type_sources'])->toBe([
        'game' => ['notifications' => ['followed']],
        'movie' => ['notifications' => ['backlog']],
    ]);

    // Named while its module is off, it is refused and stays as it was.
    $this->patch(route('settings.preferences.update'), ['type_sources' => ['game' => ['notifications' => []]]])->assertSessionHasErrors('type_sources');
    expect($user->refresh()->preferences->toStored()['type_sources']['game'])->toBe(['notifications' => ['followed']]);

    // The module comes back: the registries are built anew.
    app()->forgetInstance(MediaTypeRegistry::class);
    app()->forgetInstance(ProviderRegistry::class);
    enableGamesModule();

    $this->patch(route('settings.preferences.update'), ['type_sources' => ['game' => ['notifications' => ['backlog']]]])->assertSessionHasNoErrors();
    expect($user->refresh()->preferences->toStored()['type_sources'])->toBe(['game' => ['notifications' => ['backlog']]]);

    $this->patch(route('settings.preferences.update'), ['type_sources' => []])->assertSessionHasNoErrors();
    expect($user->refresh()->preferences->toStored()['type_sources'])->toBe([]);
});

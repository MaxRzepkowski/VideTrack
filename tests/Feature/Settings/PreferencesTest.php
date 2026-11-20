<?php

use App\Accounts\Preferences\UserPreferences;
use App\Actions\Users\UpdatePreferences;
use App\Enums\UpNextSort;
use App\Http\Requests\Settings\PreferencesUpdateRequest;
use App\Models\User;
use App\Tracking\Surface;
use App\Tracking\TrackingSource;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

test('the preferences page renders', function () {
    Http::fake(providerDictionaryFakes());

    $this->actingAs(User::factory()->create())
        ->get('/settings/preferences')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('settings/Preferences'));
});

test('spoiler protection defaults to enabled', function () {
    expect(User::factory()->create()->preferences->spoilerProtection())->toBeTrue();
});

test('updating spoiler protection persists and keeps unknown keys', function () {
    $user = User::factory()->create();
    $user->preferences = ['future_setting' => 'kept'];
    $user->save();

    $this->actingAs($user)
        ->from('/settings/preferences')
        ->patch('/settings/preferences', ['spoiler_protection' => false])
        ->assertRedirect('/settings/preferences');

    $user->refresh();

    expect($user->preferences->spoilerProtection())->toBeFalse()
        ->and($user->preferences->toStored()['future_setting'])->toBe('kept');
});

test('spoiler protection requires a boolean', function () {
    $this->actingAs(User::factory()->create())
        ->from('/settings/preferences')
        ->patch('/settings/preferences', ['spoiler_protection' => 'maybe'])
        ->assertSessionHasErrors('spoiler_protection');
});

test('a timezone preference can be set and cleared back to automatic', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->patch('/settings/preferences', ['spoiler_protection' => true, 'timezone' => 'Europe/Warsaw'])
        ->assertSessionHasNoErrors();

    expect($user->refresh()->preferences->toStored()['timezone'])->toBe('Europe/Warsaw');

    $this->actingAs($user)
        ->patch('/settings/preferences', ['spoiler_protection' => true, 'timezone' => ''])
        ->assertSessionHasNoErrors();

    expect($user->refresh()->preferences->toStored()['timezone'])->toBeNull();
});

test('calendar toggles persist and default to enabled', function () {
    enableGamesModule();
    $user = User::factory()->create();

    expect($user->preferences->matrix(Surface::Calendar))->toBe(TrackingSource::cases())
        ->and($user->preferences->perPlatform(Surface::Calendar))->toBeTrue();

    $this->actingAs($user)
        ->patch('/settings/preferences', [
            'spoiler_protection' => true,
            'calendar_dropped' => false,
            'calendar_on_hold' => true,
            'calendar_watchlist' => false,
            'calendar_followed' => false,
            'calendar_creators' => false,
            'calendar_game_platforms' => false,
        ])
        ->assertSessionHasNoErrors();

    expect($user->refresh()->preferences->matrix(Surface::Calendar))->toBe([TrackingSource::OnHold, TrackingSource::Activity])
        ->and($user->preferences->perPlatform(Surface::Calendar))->toBeFalse();
});

test('a partial update leaves absent calendar toggles untouched', function () {
    $user = User::factory()->create();
    $user->preferences = ['calendar_dropped' => false];
    $user->save();

    $this->actingAs($user)
        ->patch('/settings/preferences', ['spoiler_protection' => true])
        ->assertSessionHasNoErrors();

    expect($user->refresh()->preferences->matrix(Surface::Calendar))->not->toContain(TrackingSource::Dropped);
});

test('each settings page saves its complete form without overwriting the other page', function () {
    $preferences = [
        'spoiler_protection' => false,
        'timezone' => 'Europe/Warsaw',
        'time_format' => '24',
        'region' => 'PL',
        'up_next_target' => 'show',
        'up_next_keep_finished' => false,
        'up_next_sort' => 'remaining',
    ];
    $tracking = [
        'calendar_active' => false,
        'countdown_active' => true,
        'notifications_active' => false,
        'calendar_watchlist' => true,
        'countdown_watchlist' => false,
        'notifications_watchlist' => true,
        'calendar_on_hold' => false,
        'countdown_on_hold' => true,
        'notifications_on_hold' => false,
        'calendar_dropped' => true,
        'countdown_dropped' => false,
        'notifications_dropped' => true,
        'calendar_followed' => false,
        'countdown_followed' => true,
        'notifications_followed' => false,
        'calendar_game_platforms' => false,
        'countdown_game_platforms' => true,
        'calendar_episode_image' => 'still',
        'countdown_episode_image' => 'still',
        'notify_episodes' => false,
        'notify_premieres' => true,
        'notify_date_changes' => false,
        'notify_show_status' => true,
        'notify_person_titles' => false,
        'notify_company_titles' => true,
        'notify_collection_items' => false,
        'person_follow_scope' => 'all',
        'company_follow_scope' => 'developed',
        'calendar_release_mode' => 'cinema',
        'countdown_release_mode' => 'digital',
        'notifications_release_mode' => 'both',
    ];
    $untouched = ['future_setting' => 'kept', 'detected_timezone' => 'Asia/Tokyo'];
    $user = User::factory()->create(['preferences' => [...$tracking, ...$untouched]]);

    $this->actingAs($user)
        ->from('/settings/preferences')
        ->patch('/settings/preferences', $preferences)
        ->assertSessionHasNoErrors()
        ->assertRedirect('/settings/preferences');

    expect($user->refresh()->preferences->toStored())->toEqual([...$tracking, ...$untouched, ...$preferences]);

    // Change values from the other form while preserving every saved preference.
    $tracking['calendar_active'] = true;
    $tracking['notify_collection_items'] = true;
    $tracking['company_follow_scope'] = 'published';

    $this->from('/settings/tracking')
        ->patch('/settings/preferences', $tracking)
        ->assertSessionHasNoErrors()
        ->assertRedirect('/settings/tracking');

    expect($user->refresh()->preferences->toStored())->toEqual([...$tracking, ...$untouched, ...$preferences]);
});

test('a validation error prevents a settings form from being partially saved', function () {
    $original = ['spoiler_protection' => true, 'timezone' => 'Europe/Warsaw', 'calendar_active' => false];
    $user = User::factory()->create(['preferences' => $original]);

    $this->actingAs($user)
        ->from('/settings/preferences')
        ->patch('/settings/preferences', ['spoiler_protection' => false, 'timezone' => 'invalid'])
        ->assertSessionHasErrors('timezone');

    expect($user->refresh()->preferences->toStored())->toEqual($original);
});

test('the reported timezone persists on its own and only backs up an unset preference', function () {
    $user = User::factory()->create();

    // 204, never a redirect: the client reports this in the background, and a
    // redirect would re-render whatever page the user is on mid-interaction.
    $this->actingAs($user)
        ->patch('/settings/detected-timezone', ['detected_timezone' => 'Asia/Tokyo'])
        ->assertNoContent();

    // Reporting it never counts as choosing it: the preference stays automatic.
    expect($user->refresh()->preferences->toStored()['detected_timezone'])->toBe('Asia/Tokyo')
        ->and($user->preferences->toStored()['timezone'] ?? null)->toBeNull()
        ->and($user->preferences->effectiveTimezone())->toBe('Asia/Tokyo');

    $this->actingAs($user)
        ->patch('/settings/preferences', ['timezone' => 'Europe/Warsaw'])
        ->assertSessionHasNoErrors();

    expect($user->refresh()->preferences->effectiveTimezone())->toBe('Europe/Warsaw');
});

test('a save keeps what another request wrote since its page was read', function () {
    $user = User::factory()->create();
    // The settings page's request read the account before the browser
    // reported its timezone in the background.
    $readEarlier = User::query()->findOrFail($user->id);

    app(UpdatePreferences::class)->execute($user, ['detected_timezone' => 'Asia/Tokyo']);
    app(UpdatePreferences::class)->execute($readEarlier, ['spoiler_protection' => false]);

    expect($user->refresh()->preferences->toStored())->toMatchArray(['detected_timezone' => 'Asia/Tokyo', 'spoiler_protection' => false])
        ->and($readEarlier->preferences->toStored())->toMatchArray(['detected_timezone' => 'Asia/Tokyo']);
});

test('an invalid reported timezone is rejected', function () {
    $this->actingAs(User::factory()->create())
        ->patchJson('/settings/detected-timezone', ['detected_timezone' => 'Mars/Olympus'])
        ->assertStatus(422);
});

test('an invalid timezone is rejected', function () {
    $this->actingAs(User::factory()->create())
        ->from('/settings/preferences')
        ->patch('/settings/preferences', ['spoiler_protection' => true, 'timezone' => 'Mars/Olympus'])
        ->assertSessionHasErrors('timezone');
});

test('the region persists lowercased input and clears back to automatic', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->patch('/settings/preferences', ['spoiler_protection' => true, 'region' => 'pl'])
        ->assertSessionHasNoErrors();

    expect($user->refresh()->preferences->toStored()['region'])->toBe('PL');

    $this->actingAs($user)
        ->patch('/settings/preferences', ['spoiler_protection' => true, 'region' => ''])
        ->assertSessionHasNoErrors();

    expect($user->refresh()->preferences->toStored()['region'])->toBeNull();
});

test('an invalid region is rejected', function () {
    $this->actingAs(User::factory()->create())
        ->from('/settings/preferences')
        ->patch('/settings/preferences', ['spoiler_protection' => true, 'region' => 'POL'])
        ->assertSessionHasErrors('region');
});

test('the preferences page lists the countries the provider knows', function () {
    Http::fake([
        '*/watch/providers/regions*' => Http::response(['results' => [
            ['iso_3166_1' => 'US', 'english_name' => 'United States', 'native_name' => 'United States'],
            ['iso_3166_1' => 'PL', 'english_name' => 'Poland', 'native_name' => 'Poland'],
        ]]),
    ]);

    $this->actingAs(User::factory()->create())
        ->get('/settings/preferences')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('regions.PL', 'Poland')
            ->where('regions.US', 'United States')
            ->where('defaultRegion', 'US'));
});

test('an unreachable provider leaves the country list empty', function () {
    Http::fake(['*/watch/providers/regions*' => Http::response([], 500)]);

    $this->actingAs(User::factory()->create())
        ->get('/settings/preferences')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('regions', []));
});

test('the time format preference persists and is validated', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->patch('/settings/preferences', ['spoiler_protection' => true, 'time_format' => '24'])
        ->assertRedirect();

    expect($user->refresh()->preferences->toStored()['time_format'])->toBe('24');

    $this->actingAs($user)
        ->from('/settings/preferences')
        ->patch('/settings/preferences', ['spoiler_protection' => true, 'time_format' => 'nonsense'])
        ->assertSessionHasErrors('time_format');
});

test('the first day of the week preference persists and is validated', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->patch('/settings/preferences', ['spoiler_protection' => true, 'week_start' => 'sunday'])
        ->assertRedirect();

    expect($user->refresh()->preferences->toStored()['week_start'])->toBe('sunday');

    $this->actingAs($user)
        ->from('/settings/preferences')
        ->patch('/settings/preferences', ['spoiler_protection' => true, 'week_start' => 'caturday'])
        ->assertSessionHasErrors('week_start');
});

test('the calendar episode image preference persists and is validated', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->patch('/settings/preferences', ['spoiler_protection' => true, 'calendar_episode_image' => 'still'])
        ->assertRedirect();

    expect($user->refresh()->preferences->toStored()['calendar_episode_image'])->toBe('still');

    $this->actingAs($user)
        ->from('/settings/preferences')
        ->patch('/settings/preferences', ['spoiler_protection' => true, 'calendar_episode_image' => 'nope'])
        ->assertSessionHasErrors('calendar_episode_image');
});

test('the up next sort preference persists and is validated', function () {
    $user = User::factory()->create();

    expect($user->preferences->upNextSort())->toBe(UpNextSort::Activity);

    $this->actingAs($user)
        ->patch('/settings/preferences', ['spoiler_protection' => true, 'up_next_sort' => 'remaining'])
        ->assertRedirect();

    expect($user->refresh()->preferences->upNextSort())->toBe(UpNextSort::Remaining);

    $this->actingAs($user)
        ->from('/settings/preferences')
        ->patch('/settings/preferences', ['spoiler_protection' => true, 'up_next_sort' => 'whenever'])
        ->assertSessionHasErrors('up_next_sort');
});

test('every page shares every setting, defaults filled in, so the front keeps none of its own', function () {
    Http::fake(providerDictionaryFakes());
    $user = User::factory()->create(['preferences' => ['countdown_dropped' => true]]);

    $this->actingAs($user)->get('/settings/preferences')->assertInertia(fn (Assert $page) => $page
        // As the page decodes it: the empty map of own sources an object.
        ->where('auth.user.preferences', json_decode((string) json_encode($user->preferences->resolved()), true))
        ->where('auth.user.preferences.countdown_dropped', true)
        ->where('auth.user.preferences.notifications_dropped', false));
});

test('a flag sent as a form value is stored as a flag, a country in capitals', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->patch('/settings/preferences', ['calendar_dropped' => '0', 'notify_episodes' => '1', 'region' => 'de'])
        ->assertSessionHasNoErrors();

    expect($user->refresh()->preferences->toStored())->toEqual(['calendar_dropped' => false, 'notify_episodes' => true, 'region' => 'DE']);
});

test('a reading pace persists as a whole number and is validated', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->from(route('settings.preferences.edit'))
        ->patch(route('settings.preferences.update'), ['reading_pace_pages' => '45', 'reading_pace_issues' => 4])
        ->assertSessionHasNoErrors();

    expect($user->refresh()->preferences->toStored())->toMatchArray(['reading_pace_pages' => 45, 'reading_pace_issues' => 4]);

    $this->actingAs($user)
        ->from(route('settings.preferences.edit'))
        ->patch(route('settings.preferences.update'), ['reading_pace_chapters' => 0, 'reading_pace_pages' => UserPreferences::READING_PACE_MAX + 1])
        ->assertSessionHasErrors(['reading_pace_chapters', 'reading_pace_pages']);
});

test('every setting a page can save has a default to read it back by', function () {
    // Every kind of title present, so every setting one declares is offered.
    enableGamesModule();
    enableBooksModule();
    enableMangaModule();
    enableComicsModule();

    // A structured setting's parts are validated under its own name.
    $saved = array_filter(array_keys((new PreferencesUpdateRequest)->rules()), fn (string $key): bool => ! str_contains($key, '.'));

    expect(array_diff($saved, array_keys(UserPreferences::fromStored([])->resolved())))->toBe([]);
});

test('the timezone report keeps every own source as stored', function (array $own) {
    $user = User::factory()->create(['preferences' => ['type_sources' => $own]]);

    $this->actingAs($user)->patch(route('settings.timezone.detected'), ['detected_timezone' => 'Asia/Tokyo'])->assertNoContent();

    expect($user->refresh()->preferences->toStored()['type_sources'])->toBe($own)
        ->and($user->preferences->toStored()['detected_timezone'])->toBe('Asia/Tokyo');
})->with([
    'a kind whose module is off' => [['game' => ['notifications' => ['followed']]]],
    'a kind offered' => [['movie' => ['calendar' => ['backlog'], 'countdowns' => ['backlog']]]],
    'a kind no module has' => [['boardgame' => ['notifications' => ['backlog']], 'movie' => ['notifications' => ['backlog']]]],
    'a kind of a wrong shape' => [['book' => 'garbage', 'movie' => ['notifications' => ['backlog']]]],
]);

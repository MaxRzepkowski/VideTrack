<?php

use App\Accounts\Preferences\CompanyFollowScope;
use App\Accounts\Preferences\NotificationEvent;
use App\Accounts\Preferences\PersonFollowScope;
use App\Accounts\Preferences\UserPreferences;
use App\Enums\ReleaseDateMode;
use App\Enums\UpNextSort;
use App\Http\Requests\Settings\PreferencesUpdateRequest;
use App\Tracking\Surface;
use App\Tracking\TrackingSource;

/*
 * Every setting's default, in the one place it lives. A changed default here
 * changes it for everyone who never chose — the stored JSON holds choices only.
 */

// The front's test factory reads the same file, so its account is the server's.
test('an account that never chose anything reads every default', function () {
    expect(UserPreferences::fromStored([])->resolved())->toBe(jsonFixture('preferences/defaults'));
});

test('the typed reads of an untouched account', function () {
    $preferences = UserPreferences::fromStored([]);

    expect($preferences->spoilerProtection())->toBeTrue()
        ->and($preferences->region())->toBeNull()
        ->and($preferences->upNextSort())->toBe(UpNextSort::Activity)
        ->and($preferences->personFollowScope())->toBe(PersonFollowScope::KnownFor)
        ->and($preferences->companyFollowScope())->toBe(CompanyFollowScope::Both)
        ->and(array_filter(NotificationEvent::cases(), $preferences->notifies(...)))->toBe(NotificationEvent::cases());
});

test("each surface's column of the matrix and its own options", function (Surface $surface, array $rows, bool $perPlatform) {
    $preferences = UserPreferences::fromStored([]);

    expect($preferences->matrix($surface))->toBe($rows)
        ->and($preferences->perPlatform($surface))->toBe($perPlatform)
        ->and($preferences->releaseMode($surface))->toBe(ReleaseDateMode::Both);
})->with([
    'calendar: everything' => [Surface::Calendar, TrackingSource::cases(), true],
    'countdowns: not what was dropped' => [Surface::Countdowns, [TrackingSource::OnHold, TrackingSource::Backlog, TrackingSource::Activity, TrackingSource::ManualFollow, TrackingSource::CreatorFollow], false],
    'notifications: nothing paused or dropped' => [Surface::Notifications, [TrackingSource::Backlog, TrackingSource::Activity, TrackingSource::ManualFollow, TrackingSource::CreatorFollow], false],
]);

test("a surface's toggles and release mode are its own", function () {
    $preferences = UserPreferences::fromStored([
        'countdown_watchlist' => false,
        'countdown_game_platforms' => true,
        'countdown_release_mode' => 'digital',
    ]);

    expect($preferences->matrix(Surface::Countdowns))->not->toContain(TrackingSource::Backlog)
        ->and($preferences->perPlatform(Surface::Countdowns))->toBeTrue()
        ->and($preferences->releaseMode(Surface::Countdowns))->toBe(ReleaseDateMode::Digital)
        ->and($preferences->matrix(Surface::Calendar))->toContain(TrackingSource::Backlog)
        ->and($preferences->releaseMode(Surface::Calendar))->toBe(ReleaseDateMode::Both);
});

test('a choice that no longer means anything reads as the default', function () {
    $preferences = UserPreferences::fromStored([
        'spoiler_protection' => 'yes',
        'up_next_sort' => 'retired',
        'timezone' => '',
        'region' => 'Poland',
    ]);

    expect($preferences->spoilerProtection())->toBeTrue()
        ->and($preferences->upNextSort())->toBe(UpNextSort::Activity)
        ->and($preferences->resolved()['timezone'])->toBeNull()
        ->and($preferences->region())->toBeNull()
        ->and($preferences->resolved()['region'])->toBeNull()
        ->and(UserPreferences::fromStored(['region' => 'pl'])->resolved()['region'])->toBe('PL');
});

test('a country reads in capitals', function () {
    expect(UserPreferences::fromStored(['region' => 'de'])->region())->toBe('DE');
});

test('the timezone chosen wins over the one the browser reported', function () {
    expect(UserPreferences::fromStored(['detected_timezone' => 'Asia/Tokyo'])->effectiveTimezone())->toBe('Asia/Tokyo')
        ->and(UserPreferences::fromStored(['timezone' => 'Europe/Warsaw', 'detected_timezone' => 'Asia/Tokyo'])->effectiveTimezone())->toBe('Europe/Warsaw');
});

test('a partial write keeps every other choice, unknown ones too, and stores no defaults', function () {
    $preferences = UserPreferences::fromStored(['calendar_dropped' => false, 'future_setting' => 'kept']);

    $merged = $preferences->merge(['spoiler_protection' => false, 'timezone' => null]);

    expect($merged->toStored())->toBe(['calendar_dropped' => false, 'future_setting' => 'kept', 'spoiler_protection' => false, 'timezone' => null])
        ->and($merged->spoilerProtection())->toBeFalse()
        ->and($merged->matrix(Surface::Calendar))->not->toContain(TrackingSource::Dropped)
        ->and($preferences->spoilerProtection())->toBeTrue();
});

test('every setting a page can save has a default to read it back by', function () {
    $saved = array_keys((new PreferencesUpdateRequest)->rules());

    expect(array_diff($saved, array_keys(UserPreferences::fromStored([])->resolved())))->toBe([]);
});

<?php

use App\Accounts\Preferences\CompanyFollowScope;
use App\Accounts\Preferences\NotificationEvent;
use App\Accounts\Preferences\PersonFollowScope;
use App\Accounts\Preferences\UserPreferences;
use App\Catalog\MatureChoices;
use App\Catalog\MatureContent;
use App\Catalog\ProgressUnit;
use App\Catalog\RelatedReleaseKind;
use App\Enums\ReleaseDateMode;
use App\Enums\UpNextSort;
use App\Tracking\Surface;
use App\Tracking\TrackingSource;

/*
 * Every setting's default, in the one place it lives. A changed default here
 * changes it for everyone who never chose — the stored JSON holds choices only.
 */

// The front's test factory reads the same file, so its account is the server's.
test('an account that never chose anything reads every default', function () {
    // As the page reads them, an empty map an object.
    expect(json_encode(UserPreferences::fromStored([])->resolved()))->toBe(json_encode(json_decode((string) file_get_contents(fixture('preferences/defaults.json')))));
});

test('the typed reads of an untouched account', function () {
    $preferences = UserPreferences::fromStored([]);

    expect($preferences->spoilerProtection())->toBeTrue()
        ->and($preferences->region())->toBeNull()
        ->and($preferences->upNextSort())->toBe(UpNextSort::Activity)
        ->and($preferences->personFollowScope())->toBe(PersonFollowScope::KnownFor)
        ->and($preferences->companyFollowScope())->toBe(CompanyFollowScope::Both)
        // A long-lived game is announced DLC often: those wait until asked for.
        ->and(array_values(array_filter(NotificationEvent::cases(), $preferences->notifies(...))))
        ->toBe(array_values(array_filter(NotificationEvent::cases(), fn (NotificationEvent $event): bool => $event !== NotificationEvent::RelatedAnnounced)))
        ->and(array_filter(Surface::cases(), fn (Surface $surface): bool => array_filter(RelatedReleaseKind::cases(), fn (RelatedReleaseKind $kind): bool => ! $preferences->showsRelated($surface, $kind)) !== []))->toBe([]);
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

test('a reading pace is a whole number above nothing, and time has none', function () {
    $preferences = UserPreferences::fromStored(['reading_pace_pages' => 40, 'reading_pace_chapters' => 0, 'reading_pace_issues' => '9']);

    expect($preferences->readingPace(ProgressUnit::Pages))->toBe(40)
        ->and($preferences->readingPace(ProgressUnit::Chapters))->toBe(jsonFixture('preferences/defaults')['reading_pace_chapters'])
        ->and($preferences->readingPace(ProgressUnit::Issues))->toBe(jsonFixture('preferences/defaults')['reading_pace_issues'])
        ->and($preferences->readingPace(ProgressUnit::Minutes))->toBeNull();
});

test('a week opens on the day chosen, else on the day the language opens it', function () {
    expect(UserPreferences::fromStored(['week_start' => 'sunday'])->firstWeekday('pl'))->toBe(7)
        ->and(UserPreferences::fromStored(['week_start' => 'monday'])->firstWeekday('en_US'))->toBe(1)
        ->and(UserPreferences::fromStored([])->firstWeekday('pl'))->toBe(1)
        ->and(UserPreferences::fromStored([])->firstWeekday('en_US'))->toBe(7);
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

test("a kind of title's own sources are its whole say on a surface, the rest follows the defaults", function () {
    $preferences = UserPreferences::fromStored(['calendar_dropped' => false, 'type_sources' => ['book' => [
        'calendar' => ['dropped', 'backlog'],
        'countdowns' => [],
    ]]]);

    expect($preferences->matrix(Surface::Calendar, 'book'))->toBe([TrackingSource::Dropped, TrackingSource::Backlog])
        ->and($preferences->matrix(Surface::Countdowns, 'book'))->toBe([])
        ->and($preferences->matrix(Surface::Notifications, 'book'))->toBe($preferences->matrix(Surface::Notifications))
        ->and($preferences->matrix(Surface::Calendar, 'movie'))->toBe($preferences->matrix(Surface::Calendar))
        ->and($preferences->matrix(Surface::Calendar))->not->toContain(TrackingSource::Dropped);
});

test('own sources that no longer mean anything read as no say at all', function (mixed $stored, string $resolved) {
    $preferences = UserPreferences::fromStored(['type_sources' => $stored]);

    expect(json_encode($preferences->resolved()['type_sources']))->toBe($resolved);
})->with([
    'not a map' => ['everything', '{}'],
    'a list' => [[['notifications' => ['backlog']]], '{}'],
    'a kind of title that is not a map' => [['book' => 'calendar'], '{}'],
    'a surface that is gone' => [['book' => ['digest' => ['backlog']]], '{}'],
    'a surface that is not a list' => [['book' => ['notifications' => 'backlog']], '{}'],
    'a row that is gone, or not a row' => [['book' => ['notifications' => ['backlog', 'someday', 7, null, ['x']]]], '{"book":{"notifications":["backlog"]}}'],
    'a row twice' => [['book' => ['notifications' => ['backlog', 'backlog']]], '{"book":{"notifications":["backlog"]}}'],
    'rows under names' => [['book' => ['notifications' => ['a' => 'activity', 'b' => 'creator']]], '{"book":{"notifications":["activity","creator"]}}'],
    'the calendar without the countdowns' => [['book' => ['calendar' => ['backlog'], 'notifications' => []]], '{"book":{"notifications":[]}}'],
    'the countdowns alone' => [['book' => ['countdowns' => ['backlog']]], '{}'],
    'nothing at all on every surface' => [['book' => ['calendar' => [], 'countdowns' => [], 'notifications' => []]], '{"book":{"calendar":[],"countdowns":[],"notifications":[]}}'],
]);

test('the adult content levels read what still means something, and no choice as Suggestive', function () {
    $preferences = UserPreferences::fromStored(['mature_content' => ['films' => 'adult', 'comics' => 'nsfw', 'books' => 7, 12 => 'safe']]);

    expect($preferences->matureContent()->for('films'))->toBe(MatureContent::Adult)
        ->and($preferences->matureContent()->for('comics'))->toBe(MatureChoices::shared()->for('comics'))
        ->and($preferences->matureContent()->for('books'))->toBe(MatureChoices::shared()->for('books'))
        ->and($preferences->matureContent()->for('games'))->toBe(MatureChoices::shared()->for('games'))
        ->and($preferences->resolved()['mature_content'])->toBe(['films' => 'adult']);
});

test('a stored adult content setting that is not a map reads as no choice', function () {
    expect(json_encode(UserPreferences::fromStored(['mature_content' => 'adult'])->resolved()['mature_content']))->toBe('{}');
});

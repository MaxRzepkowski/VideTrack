<?php

use App\Accounts\Preferences\UserPreferences;
use App\Tracking\Data\TrackingFacts;
use App\Tracking\Surface;
use App\Tracking\TrackingSource;

/*
 * The one table of the tracking matrix's rows: whatever a user did with a
 * title, it falls in exactly one row, the first that applies — the order the
 * bell reads it in.
 */

test('a title falls in the first row that applies', function (array $facts, ?TrackingSource $row) {
    expect((new TrackingFacts(...$facts))->source())->toBe($row);
})->with([
    'untouched' => [[], null],
    'muted, whatever else' => [['muted' => true, 'onHold' => true, 'backlog' => true, 'activity' => true, 'manualFollow' => true], null],
    'on hold over a drop' => [['onHold' => true, 'dropped' => true, 'activity' => true], TrackingSource::OnHold],
    'dropped over the backlog' => [['dropped' => true, 'backlog' => true, 'activity' => true], TrackingSource::Dropped],
    'on hold over the backlog' => [['onHold' => true, 'backlog' => true], TrackingSource::OnHold],
    'a status over a bell' => [['dropped' => true, 'manualFollow' => true, 'creatorFollow' => true], TrackingSource::Dropped],
    'the backlog over history' => [['backlog' => true, 'activity' => true], TrackingSource::Backlog],
    'the backlog over a bell' => [['backlog' => true, 'manualFollow' => true], TrackingSource::Backlog],
    'history over a bell' => [['activity' => true, 'manualFollow' => true, 'creatorFollow' => true], TrackingSource::Activity],
    'your own bell over a creator' => [['manualFollow' => true, 'creatorFollow' => true], TrackingSource::ManualFollow],
    'a creator alone' => [['creatorFollow' => true], TrackingSource::CreatorFollow],
]);

test('facts without their kind of title cannot say which surface carries them', function () {
    expect(fn () => Surface::Calendar->carries(UserPreferences::fromStored([]), new TrackingFacts(backlog: true)))
        ->toThrow(LogicException::class);
});

test('a surface carries a title by its row, as its kind of title has the rows', function () {
    $preferences = UserPreferences::fromStored(['type_sources' => ['book' => ['notifications' => ['followed']]]]);

    expect(Surface::Notifications->carries($preferences, new TrackingFacts(backlog: true, type: 'book')))->toBeFalse()
        ->and(Surface::Notifications->carries($preferences, new TrackingFacts(manualFollow: true, type: 'book')))->toBeTrue()
        ->and(Surface::Notifications->carries($preferences, new TrackingFacts(backlog: true, type: 'movie')))->toBeTrue()
        ->and(Surface::Notifications->carries($preferences, new TrackingFacts(muted: true, backlog: true, type: 'movie')))->toBeFalse();
});

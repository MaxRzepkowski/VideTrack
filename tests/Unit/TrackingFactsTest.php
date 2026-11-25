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
    'your own bell over everything else' => [['manualFollow' => true, 'backlog' => true, 'dropped' => true, 'onHold' => true, 'activity' => true, 'creatorFollow' => true], TrackingSource::ManualFollow],
    'your own bell over the backlog' => [['backlog' => true, 'manualFollow' => true], TrackingSource::ManualFollow],
    'your own bell over a status' => [['dropped' => true, 'manualFollow' => true], TrackingSource::ManualFollow],
    'the backlog over a drop' => [['dropped' => true, 'backlog' => true, 'activity' => true], TrackingSource::Backlog],
    'the backlog over a pause' => [['onHold' => true, 'backlog' => true], TrackingSource::Backlog],
    'a drop over a pause' => [['onHold' => true, 'dropped' => true, 'activity' => true], TrackingSource::Dropped],
    'a status over history' => [['onHold' => true, 'activity' => true], TrackingSource::OnHold],
    'the backlog over history' => [['backlog' => true, 'activity' => true], TrackingSource::Backlog],
    'history over a creator' => [['activity' => true, 'creatorFollow' => true], TrackingSource::Activity],
    'a status over a creator' => [['dropped' => true, 'creatorFollow' => true], TrackingSource::Dropped],
    'a creator alone' => [['creatorFollow' => true], TrackingSource::CreatorFollow],
]);

test('every stance the user took counts as theirs, a bell a followed creator rang does not', function (array $facts, bool $chosen) {
    expect((new TrackingFacts(...$facts))->isChosenByUser())->toBe($chosen);
})->with([
    'untouched' => [[], false],
    'muted' => [['muted' => true], true],
    'on hold' => [['onHold' => true], true],
    'dropped' => [['dropped' => true], true],
    'on the backlog' => [['backlog' => true], true],
    'with history' => [['activity' => true], true],
    'their own bell' => [['manualFollow' => true], true],
    'a creator\'s bell alone' => [['creatorFollow' => true], false],
    'a creator\'s bell beside their own' => [['creatorFollow' => true, 'manualFollow' => true], true],
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

<?php

use App\Catalog\Creators\CreatorKind;
use App\Enums\FollowMode;
use App\Enums\MediaUserStatus;
use App\Enums\PlayOutcome;
use App\Integrations\VideTrack\Backup\BackupCreator;
use App\Integrations\VideTrack\Backup\BackupCycle;
use App\Integrations\VideTrack\Backup\BackupFavorite;
use App\Integrations\VideTrack\Backup\BackupFollow;
use App\Integrations\VideTrack\Backup\BackupFormat;
use App\Integrations\VideTrack\Backup\BackupList;
use App\Integrations\VideTrack\Backup\BackupListItem;
use App\Integrations\VideTrack\Backup\BackupPlay;
use App\Integrations\VideTrack\Backup\BackupPosition;
use App\Integrations\VideTrack\Backup\BackupRating;
use App\Integrations\VideTrack\Backup\BackupStatus;
use App\Integrations\VideTrack\Backup\InvalidBackupEntry;
use Carbon\CarbonImmutable;

/**
 * What a backup entry writes, its own class reads back as the same entry —
 * and a file without the optional fields still reads.
 */
function backupShow(): BackupPosition
{
    return new BackupPosition('show', 'tmdb', '1396', 'Breaking Bad');
}

test('every kind of entry reads back as it was written', function (object $entry) {
    $copy = $entry::fromArray(json_decode((string) json_encode($entry->toArray()), true));

    expect($copy)->toEqual($entry);
})->with([
    'a play in a later run' => fn () => new BackupPlay(backupShow(), CarbonImmutable::parse('2026-03-01T20:00:00+00:00'), PlayOutcome::Dropped, null, 2),
    'a play with hours' => fn () => new BackupPlay(new BackupPosition('game', 'igdb', '1942', 'The Witcher 3'), CarbonImmutable::parse('2026-03-01T20:00:00+00:00'), PlayOutcome::Completed, 3120, 1),
    'a rating' => fn () => new BackupRating(backupShow(), 9, CarbonImmutable::parse('2026-02-03T12:00:00+00:00')),
    'a rating without a date' => fn () => new BackupRating(backupShow(), 9, null),
    'a favorite' => fn () => new BackupFavorite(backupShow(), CarbonImmutable::parse('2026-02-04T08:30:00+00:00')),
    'an open attempt' => fn () => new BackupStatus(backupShow(), MediaUserStatus::OnHold, null, 12),
    'a later run' => fn () => new BackupCycle(backupShow(), 2, CarbonImmutable::parse('2026-06-01T12:00:00+00:00')),
    'a muted title' => fn () => new BackupFollow(backupShow(), FollowMode::Mute),
    'a followed collection' => fn () => new BackupFollow(new BackupCreator(CreatorKind::Collection, 'tmdb', '2344', 'The Saga'), FollowMode::Follow),
    'a followed studio' => fn () => new BackupFollow(new BackupCreator(CreatorKind::Company, 'tmdb', '174', 'AMC Studios'), FollowMode::Follow),
    'a list of a title, a person and a network' => fn () => new BackupList('Best of', 'Mine', true, null, [
        new BackupListItem(backupShow(), CarbonImmutable::parse('2026-01-01T00:00:00+00:00')),
        new BackupListItem(new BackupCreator(CreatorKind::Person, 'tmdb', '17419', 'Bryan Cranston'), null),
        new BackupListItem(new BackupCreator(CreatorKind::Network, 'tmdb', '174', 'AMC'), null),
    ]),
]);

test('a play of the first run leaves the run out of the file, and a file without it reads as the first run', function () {
    $play = new BackupPlay(backupShow(), CarbonImmutable::parse('2026-03-01T20:00:00+00:00'), PlayOutcome::Completed, null, 1);

    expect($play->toArray())->not->toHaveKey('cycle')
        ->and(BackupPlay::fromArray($play->toArray())->cycle)->toBe(1);
});

test('a status reads as the open attempt it names', function () {
    expect(BackupStatus::fromArray(['type' => 'show', 'provider' => 'tmdb', 'ref' => '1396', 'status' => 'on_hold'])->status)->toBe(MediaUserStatus::OnHold);
});

test('a broken entry says what is missing', function (Closure $read, string $reason) {
    expect($read)->toThrow(InvalidBackupEntry::class, $reason);
})->with([
    'no coordinates' => [fn () => BackupFavorite::fromArray(['title' => 'Nameless']), 'import.skip_backup_no_position'],
    'no date' => [fn () => BackupPlay::fromArray(['type' => 'movie', 'provider' => 'tmdb', 'ref' => '603', 'watched_at' => '']), 'import.skip_no_watched_at'],
    'a status this version does not know' => [fn () => BackupStatus::fromArray(['type' => 'show', 'provider' => 'tmdb', 'ref' => '1396', 'status' => 'dropped']), 'import.skip_backup_unknown_status'],
    'no outcome' => [fn () => BackupPlay::fromArray(['type' => 'movie', 'provider' => 'tmdb', 'ref' => '603', 'watched_at' => '2026-01-05T20:00:00+00:00']), 'import.skip_backup_no_outcome'],
    'no rating' => [fn () => BackupRating::fromArray(['type' => 'movie', 'provider' => 'tmdb', 'ref' => '603']), 'import.skip_backup_no_rating'],
    'a creator without a name' => [fn () => BackupListItem::fromArray(['type' => 'person', 'provider' => 'tmdb', 'ref' => '1']), 'import.skip_backup_creator_incomplete'],
    'a first run' => [fn () => BackupCycle::fromArray(['type' => 'show', 'provider' => 'tmdb', 'ref' => '1396', 'number' => 1, 'started_at' => '2026-06-01T12:00:00+00:00']), 'import.skip_backup_cycle_incomplete'],
    'a follow without a mode' => [fn () => BackupFollow::fromArray(['type' => 'show', 'provider' => 'tmdb', 'ref' => '1396', 'mode' => 'loud']), 'import.skip_backup_follow_no_mode'],
    'a collection on a list' => [fn () => BackupListItem::fromArray(['type' => 'collection', 'provider' => 'tmdb', 'ref' => '1', 'title' => 'Saga']), 'import.skip_backup_collection_on_list'],
]);

test('a list keeps the positions it can read and says why it left the others out', function () {
    $list = BackupList::fromArray(['title' => 'Mixed', 'items' => [
        ['type' => 'movie', 'provider' => 'tmdb', 'ref' => '603'],
        ['title' => 'Nameless'],
    ]]);

    expect($list?->items)->toHaveCount(1)
        ->and($list?->unreadable)->toHaveCount(1)
        ->and(BackupList::fromArray(['items' => []]))->toBeNull();
});

test('only our own format of a version this instance knows is read', function (mixed $data, bool $reads) {
    expect(BackupFormat::reads($data))->toBe($reads);
})->with([
    'ours' => [['format' => 'videtrack-export', 'version' => 1], true],
    'newer' => [['format' => 'videtrack-export', 'version' => 2], false],
    'someone else\'s' => [['format' => 'trakt', 'version' => 1], false],
    'not an object' => ['videtrack-export', false],
]);

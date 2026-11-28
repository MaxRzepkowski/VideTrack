<?php

use App\Actions\Scrobble\ApplyScrobbleEvent;
use App\Catalog\MediaKind;
use App\Enums\PlayOutcome;
use App\Integrations\Import\Data\ImportPlay;
use App\Integrations\Import\Rows\MediaRow;
use App\Integrations\Scrobble\Data\ScrobbleEvent;
use App\Integrations\Scrobble\DeliveryNote;
use App\Integrations\Scrobble\ScrobbleEventKind;
use App\Library\MutationContext;
use App\Library\MutationSource;
use App\Library\PlayDating;
use App\Library\RecordKind;
use App\Library\ScrobbleDedup;
use App\Models\MediaItem;
use App\Models\Play;
use App\Models\User;
use Carbon\CarbonImmutable;
use Tests\Fakes\FakeImportSource;

/** A finished viewing the library already holds, from $source (null: never kept). */
function heldViewing(User $user, MediaItem $movie, CarbonImmutable $at, ?string $source, PlayOutcome $outcome = PlayOutcome::Completed): void
{
    Play::factory()->create(['user_id' => $user->id, 'media_item_id' => $movie->id, 'watched_at' => $at, 'source' => $source, 'outcome' => $outcome]);
    projectLibrary($user);
}

dataset('an imported viewing beside one the library holds', function () {
    $own = MutationSource::import(FakeImportSource::CODE)->value();
    $server = MutationSource::scrobble('jellyfin')->value();
    // Hours past the edge of the window, which the config sets once the app is up.
    $inside = -1;
    $outside = 1;
    $viewing = fn (CarbonImmutable $at) => new ImportPlay($at);

    return [
        'another app reported it, hours off' => [$server, $inside, PlayOutcome::Completed, $viewing, 1],
        'a click, hours off' => [MutationSource::ui()->value(), $inside, PlayOutcome::Completed, $viewing, 1],
        'a history that never kept its source' => [null, $inside, PlayOutcome::Completed, $viewing, 1],
        'another source, outside the window' => [$server, $outside, PlayOutcome::Completed, $viewing, 2],
        'the same source, another moment: a rewatch' => [$own, $inside, PlayOutcome::Completed, $viewing, 2],
        'only an abandoned attempt nearby' => [$server, $inside, PlayOutcome::Dropped, $viewing, 2],
        'a restored backup, as it was, though it never kept its own source' => [$server, $inside, PlayOutcome::Completed, fn (CarbonImmutable $at) => new ImportPlay($at, record: RecordKind::Backup), 2],
        'a media server\'s log, beside its own webhook' => [MutationSource::scrobble('tracearr')->value(), $inside, PlayOutcome::Completed, fn (CarbonImmutable $at) => new ImportPlay($at, record: RecordKind::PlaybackLog), 1],
        'a media server\'s log, two sessions of one viewing' => [$own, $inside, PlayOutcome::Completed, fn (CarbonImmutable $at) => new ImportPlay($at, record: RecordKind::PlaybackLog), 1],
    ];
});

test('an imported viewing another source already logged is that viewing', function (?string $heldFrom, int $pastTheEdge, PlayOutcome $heldOutcome, Closure $play, int $plays) {
    $movie = MediaItem::factory()->movie()->create();
    $user = User::factory()->create();
    $held = CarbonImmutable::parse('2026-03-01 21:00');

    heldViewing($user, $movie, $held, $heldFrom, $heldOutcome);

    runFakeImport($user, [new MediaRow(subjectOf($movie), plays: [$play($held->addHours(ScrobbleDedup::windowHours() + $pastTheEdge))])]);

    expect($user->plays()->count())->toBe($plays);
})->with('an imported viewing beside one the library holds');

test('a media server weighs its own reports too: a stop and the "watched" after it are one viewing', function (?string $heldFrom) {
    $movie = MediaItem::factory()->movie()->create();
    $user = User::factory()->create();
    $held = CarbonImmutable::parse('2026-03-01 21:00');

    heldViewing($user, $movie, $held, $heldFrom);

    $outcome = app(ApplyScrobbleEvent::class)->execute($user, $movie, new ScrobbleEvent(ScrobbleEventKind::Complete, MediaKind::Movie, [], occurredAt: $held->addMinutes(5)));

    expect($outcome->note)->toBe(DeliveryNote::AlreadyPlayed)
        ->and($user->plays()->count())->toBe(1);
})->with([
    'the same server' => fn () => MutationSource::scrobble('fake')->value(),
    'a click' => fn () => MutationSource::ui()->value(),
    'an import' => fn () => MutationSource::import(FakeImportSource::CODE)->value(),
]);

test('a date that is only the source\'s last activity, or none, says nothing about the moment', function () {
    $movie = MediaItem::factory()->movie()->create();
    $user = User::factory()->create();
    $held = CarbonImmutable::parse('2026-03-01 21:00');

    heldViewing($user, $movie, $held, MutationSource::scrobble('jellyfin')->value());

    $weigh = fn (?CarbonImmutable $at, PlayDating $dating): bool => app(MutationContext::class)->run(
        MutationSource::import(FakeImportSource::CODE),
        fn (): bool => app(ScrobbleDedup::class)->reportedElsewhere($user, $movie, $at, $dating),
    );

    expect($weigh($held->addHour(), PlayDating::Viewing))->toBeTrue()
        ->and($weigh($held->addHour(), PlayDating::Activity))->toBeFalse()
        ->and($weigh(null, PlayDating::Viewing))->toBeFalse();
});

test('the very moment another source logged is the same entry, which the import completes', function (string $zone, Closure $stamp) {
    instanceTimezone($zone);
    enableMangaModule();

    $manga = MediaItem::factory()->manga()->create();
    $user = User::factory()->create();
    $read = CarbonImmutable::parse('2026-03-01 21:00');

    Play::factory()->create(['user_id' => $user->id, 'media_item_id' => $manga->id, 'watched_at' => $stamp($read), 'source' => MutationSource::scrobble('kavita')->value()]);
    projectLibrary($user);

    runFakeImport($user, [new MediaRow(subjectOf($manga), plays: [new ImportPlay($read, amount: 380)])]);

    expect($user->plays()->sole()->amount)->toBe(380);
})->with([
    'on the instance\'s clock' => ['UTC', fn (CarbonImmutable $at) => $at],
    'on UTC\'s wall clock, the instance elsewhere' => ['Europe/Warsaw', fn (CarbonImmutable $at) => $at->utc()],
]);

<?php

use App\Actions\Media\RemoveOrphanTitles;
use App\Catalog\MediaItemSummaries;
use App\Library\RecentViews;
use App\Models\Company;
use App\Models\Credit;
use App\Models\Follow;
use App\Models\ListItem;
use App\Models\MaintenanceCheckState;
use App\Models\MediaItem;
use App\Models\MediaList;
use App\Models\Person;
use App\Models\Play;
use App\Models\TaskRun;
use App\Models\User;
use App\Tasks\Maintenance\Checks\OrphanSweep;
use App\Tasks\Maintenance\Data\CheckLine;
use App\Tasks\Maintenance\MaintenanceChecks;
use App\Tasks\Maintenance\OrphanKind;

/** Saved before the grace began, so only what holds it can keep it. */
function savedLongAgo(): array
{
    return ['created_at' => now()->subDays(config()->integer('tracker.retention.orphan_grace_days') + 1)];
}

/** Saved within the grace: too fresh to count as an orphan yet. */
function savedLately(): array
{
    return ['created_at' => now()->subDays(config()->integer('tracker.retention.orphan_grace_days') - 1)];
}

function sweepOrphans(): MaintenanceCheckState
{
    app(MaintenanceChecks::class)->perform('run-of-alex', [OrphanSweep::CODE]);

    return MaintenanceCheckState::query()->where('code', OrphanSweep::CODE)->sole();
}

/** What the last sweep said of a kind, as the row reads it. */
function sweptState(MaintenanceCheckState $state, OrphanKind $kind): string
{
    return collect(CheckLine::listFromStored($state->result))->firstWhere('subject', $kind->value)->state->wording();
}

test('a title nobody holds goes with its tree, and the row and the history say so', function () {
    $show = MediaItem::factory()->show()->create(savedLongAgo());
    $season = MediaItem::factory()->season($show, 1)->create(savedLongAgo());
    MediaItem::factory()->episode($season, 1)->create(savedLongAgo());

    $state = sweepOrphans();

    expect(MediaItem::query()->count())->toBe(0)
        ->and(sweptState($state, OrphanKind::Titles))->toBe(__('tasks.removed_count', ['count' => 1]))
        ->and(TaskRun::query()->sole()->summary)->toBe(__('tasks.removed_orphans', ['titles' => 1, 'people' => 0, 'companies' => 0, 'collections' => 0]));
});

test('a quiet night leaves no history', function () {
    $state = sweepOrphans();

    expect(sweptState($state, OrphanKind::Titles))->toBe(__('tasks.removed_none'))
        ->and(TaskRun::query()->count())->toBe(0);
});

test('a title anything points at stays', function (Closure $hold) {
    $show = MediaItem::factory()->show()->create(savedLongAgo());
    $season = MediaItem::factory()->season($show, 1)->create(savedLongAgo());
    $episode = MediaItem::factory()->episode($season, 1)->create(savedLongAgo());

    $hold($show, $episode);
    projectEveryLibrary();
    sweepOrphans();

    expect(MediaItem::query()->whereKey($show->id)->exists())->toBeTrue();
})->with([
    "a play on one of its episodes, another user's too" => [fn (MediaItem $show, MediaItem $episode) => Play::factory()->create(['media_item_id' => $episode->id])],
    "an entry on a user's list" => [fn (MediaItem $show) => ListItem::factory()->create(['media_item_id' => $show->id])],
    'a bell a followed creator rang' => [function (MediaItem $show) {
        $bell = Follow::factory()->create(['media_item_id' => $show->id]);
        $bell->viaPeople()->attach(Person::factory()->create());
    }],
    'somebody opening it lately' => [fn (MediaItem $show) => app(RecentViews::class)->record(User::factory()->create(), MediaItemSummaries::summary($show))],
    'being saved within the grace' => [fn (MediaItem $show) => $show->update(savedLately())],
]);

test('a title last opened before the window goes', function () {
    MediaItem::factory()->movie()->create([...savedLongAgo(), 'viewed_at' => now()->subDays(config()->integer('tracker.retention.orphan_recent_view_days') + 1)]);

    sweepOrphans();

    expect(MediaItem::query()->count())->toBe(0);
});

test('a person or company nothing credits, follows or lists goes; any of these keeps one', function (string $model, Closure $hold) {
    $kept = $model::factory()->create(savedLongAgo());
    $alone = $model::factory()->create(savedLongAgo());

    $hold($kept);
    sweepOrphans();

    expect($model::query()->pluck('id')->all())->toBe([$kept->id]);
})->with([
    'a person with a credit' => [Person::class, fn (Person $person) => Credit::factory()->create(['person_id' => $person->id, 'media_item_id' => MediaItem::factory()->movie()->create()->id])],
    'a person with a bell' => [Person::class, fn (Person $person) => Follow::factory()->create(['media_item_id' => null, 'person_id' => $person->id])],
    'a person backing a bell' => [Person::class, fn (Person $person) => Follow::factory()->create()->viaPeople()->attach($person)],
    "a person on a user's list" => [Person::class, fn (Person $person) => ListItem::factory()->create(['media_item_id' => null, 'person_id' => $person->id])],
    'a person saved lately' => [Person::class, fn (Person $person) => $person->update(savedLately())],
    'a company with a bell' => [Company::class, fn (Company $company) => Follow::factory()->create(['media_item_id' => null, 'company_id' => $company->id])],
    'a company backing a bell' => [Company::class, fn (Company $company) => Follow::factory()->create()->viaCompanies()->attach($company)],
    "a company on a user's list" => [Company::class, fn (Company $company) => ListItem::factory()->create(['media_item_id' => null, 'company_id' => $company->id])],
    'a company saved lately' => [Company::class, fn (Company $company) => $company->update(savedLately())],
]);

test('people a removed title leaves without credits go the same night, and the row says how many', function () {
    $cast = Person::factory()->create(savedLongAgo());
    Credit::factory()->create(['person_id' => $cast->id, 'media_item_id' => MediaItem::factory()->movie()->create(savedLongAgo())->id]);

    $state = sweepOrphans();

    expect(Person::query()->count())->toBe(0)
        ->and(sweptState($state, OrphanKind::People))->toBe(__('tasks.removed_count', ['count' => 1]));
});

test('a collection left with no part and no bell goes; a part or a bell keeps it', function () {
    $empty = MediaList::factory()->collection('tmdb', '10')->create();
    $withPart = MediaList::factory()->collection('tmdb', '11')->create();
    ListItem::factory()->create(['list_id' => $withPart->id]);
    $followed = MediaList::factory()->collection('tmdb', '12')->create();
    Follow::factory()->create(['media_item_id' => null, 'list_id' => $followed->id]);

    sweepOrphans();

    expect(MediaList::query()->collections()->pluck('id')->all())->toEqualCanonicalizing([$withPart->id, $followed->id])
        ->and(MediaList::query()->whereKey($empty->id)->exists())->toBeFalse();
});

test('a night removes the whole backlog, chunk after chunk', function () {
    config(['tracker.retention.orphan_chunk' => 1]);
    MediaItem::factory()->movie()->count(3)->create(savedLongAgo());

    $state = sweepOrphans();

    expect(MediaItem::query()->count())->toBe(0)
        ->and(sweptState($state, OrphanKind::Titles))->toBe(__('tasks.removed_count', ['count' => 3]));
});

test('a night past its time leaves the rest for the nights after, and says so', function () {
    config(['tracker.retention.orphan_seconds_per_night' => 0]);
    MediaItem::factory()->movie()->count(2)->create(savedLongAgo());

    $state = sweepOrphans();

    expect(MediaItem::query()->count())->toBe(2)
        ->and(sweptState($state, OrphanKind::Titles))->toBe(__('tasks.orphan_state_removed_of', ['count' => 0, 'total' => 2]));
});

test('the removal asks the trees again and keeps a title taken up since the sweep looked', function (Closure $hold) {
    $show = MediaItem::factory()->show()->create(savedLongAgo());
    $season = MediaItem::factory()->season($show, 1)->create(savedLongAgo());
    $episode = MediaItem::factory()->episode($season, 1)->create(savedLongAgo());
    $orphan = MediaItem::factory()->movie()->create(savedLongAgo());

    $hold($show, $episode);
    projectEveryLibrary();

    expect(app(RemoveOrphanTitles::class)->execute([$show->id, $orphan->id]))->toBe(1)
        ->and(MediaItem::query()->whereKey($show->id)->exists())->toBeTrue()
        ->and(MediaItem::query()->whereKey($orphan->id)->exists())->toBeFalse();
})->with([
    'a play on one of its episodes' => [fn (MediaItem $show, MediaItem $episode) => Play::factory()->create(['media_item_id' => $episode->id])],
    'an entry on a list' => [fn (MediaItem $show) => ListItem::factory()->create(['media_item_id' => $show->id])],
    'a bell a followed creator rang' => [fn (MediaItem $show) => Follow::factory()->create(['media_item_id' => $show->id])->viaPeople()->attach(Person::factory()->create())],
]);

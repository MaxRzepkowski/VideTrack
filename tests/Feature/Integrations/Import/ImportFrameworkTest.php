<?php

use App\Catalog\MediaKind;
use App\Enums\TaskRunStatus;
use App\Http\Requests\Settings\ImportRequest;
use App\Integrations\Import\Data\ImportInput;
use App\Integrations\Import\Data\ImportListEntry;
use App\Integrations\Import\Data\ImportListRef;
use App\Integrations\Import\Data\ImportPlay;
use App\Integrations\Import\Data\ImportRating;
use App\Integrations\Import\Data\ImportSelection;
use App\Integrations\Import\Data\MediaSubject;
use App\Integrations\Import\ImportSourceRegistry;
use App\Integrations\Import\Rows\ListRow;
use App\Integrations\Import\Rows\MediaRow;
use App\Integrations\Import\Rows\SkipRow;
use App\Jobs\RunImport;
use App\Library\Events\PlayRecorded;
use App\Models\MediaItem;
use App\Models\TaskRun;
use App\Models\User;
use App\Support\ExternalServiceException;
use App\Support\NotFoundAtService;
use App\Support\ServiceRejected;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia;
use Tests\Fakes\FakeImportSource;

/**
 * A minimal Trakt ZIP at a temp path, for the analyze endpoint.
 */
function traktUpload(): UploadedFile
{
    $tmp = scratchPath('trakt', '.zip');
    $zip = new ZipArchive;
    $zip->open($tmp, ZipArchive::CREATE);
    $zip->addFromString('user-profile.json', (string) json_encode(['username' => 'tester']));
    $zip->addFromString('watched-history-1.json', (string) json_encode([
        ['watched_at' => '2025-01-10T20:00:00.000Z', 'type' => 'movie', 'movie' => ['title' => 'The Matrix', 'ids' => ['tmdb' => 603]]],
    ]));
    $zip->addFromString('lists-lists.json', (string) json_encode([
        ['name' => 'My List', 'privacy' => 'public', 'item_count' => 3, 'ids' => ['trakt' => 555]],
    ]));
    $zip->close();

    return new UploadedFile($tmp, 'trakt.zip', 'application/zip', null, true);
}

test('the import page lists the registered sources in order', function () {
    $this->actingAs(User::factory()->create())
        ->get('/settings/import')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('settings/Import')
            ->where('sources.0.code', 'videtrack')
            ->where('sources.1.code', 'yamtrack')
            ->where('sources.2.code', 'trakt')
            ->where('sources.2.inputKind', 'file')
            ->where('sources.2.accept', '.zip')
            ->where('sources.2.notes.0.url', 'https://trakt.tv/settings/data')
            // The page says the very limit the upload is checked against.
            ->where('maxFileMegabytes', intdiv(ImportRequest::MAX_FILE_KB, 1024)));
});

test('analyzing a Trakt export returns its categories and lists', function () {
    $this->actingAs(User::factory()->create())
        ->post('/settings/import/trakt/analyze', ['file' => traktUpload()])
        ->assertOk()
        ->assertJsonPath('valid', true)
        ->assertJsonPath('supportsSelection', true)
        ->assertJsonPath('categories.0.key', 'history')
        ->assertJsonPath('categories.0.count', 1)
        ->assertJsonPath('lists.0.id', '555')
        ->assertJsonPath('lists.0.itemCount', 3);
});

test('analyzing a file that is not the source export says so', function () {
    $this->actingAs(User::factory()->create())
        ->post('/settings/import/trakt/analyze', ['file' => UploadedFile::fake()->createWithContent('x.zip', 'nope')])
        ->assertOk()
        ->assertJsonPath('valid', false)
        ->assertJsonPath('error', 'This file is not a Trakt export.');

    $this->post('/settings/import/yamtrack/analyze', ['file' => UploadedFile::fake()->createWithContent('x.csv', "a,b\n1,2\n")])
        ->assertJsonPath('valid', false)
        ->assertJsonPath('error', 'The file is not a Yamtrack CSV export.');
});

test('an export with a file that unpacks past the limit stops at analysis', function () {
    // One megabyte of limit, two of history: the worker never decodes it.
    config(['tracker.imports.max_json_megabytes' => 1]);

    $tmp = scratchPath('trakt', '.zip');
    $zip = new ZipArchive;
    $zip->open($tmp, ZipArchive::CREATE);
    $zip->addFromString('user-profile.json', (string) json_encode(['username' => 'tester']));
    $zip->addFromString('watched-history-1.json', '['.str_repeat('{"type":"movie"},', 120_000).'{}]');
    $zip->close();

    $this->actingAs(User::factory()->create())
        ->post('/settings/import/trakt/analyze', ['file' => new UploadedFile($tmp, 'trakt.zip', 'application/zip', null, true)])
        ->assertOk()
        ->assertJsonPath('valid', false)
        ->assertJsonPath('error', 'A file in this export is too large to import.');
});

test('analyzing an unknown source is a 404', function () {
    $this->actingAs(User::factory()->create())
        ->post('/settings/import/letterboxd/analyze', ['file' => UploadedFile::fake()->createWithContent('x.csv', 'x')])
        ->assertNotFound();
});

test('a Trakt upload queues the run with the ticked selection', function () {
    Queue::fake();

    $user = User::factory()->create();

    $this->actingAs($user)
        ->post('/settings/import', [
            'source' => 'trakt',
            'file' => traktUpload(),
            'categories' => ['history'],
            'list_ids' => ['555'],
        ])
        ->assertRedirect();

    Queue::assertPushed(RunImport::class);

    expect(TaskRun::query()->where('name', 'import-trakt')->where('user_id', $user->id)->exists())->toBeTrue();
});

test('a credentials source asks for its fields, not a file', function () {
    app(ImportSourceRegistry::class)->register(FakeImportSource::CODE, FakeImportSource::class);
    Queue::fake();

    $user = User::factory()->create();

    $this->actingAs($user)
        ->post('/settings/import', ['source' => 'fake'])
        ->assertSessionHasErrors(['credentials.username'])
        ->assertSessionDoesntHaveErrors(['file']);

    $this->post('/settings/import', ['source' => 'fake', 'credentials' => ['username' => '  someone  ']])
        ->assertSessionHasNoErrors();

    Queue::assertPushed(RunImport::class, function (RunImport $job) {
        // The queue table never sees the value in clear.
        return ! str_contains(serialize($job), 'someone');
    });
});

test('the run hands the source its credentials', function () {
    runFakeImport(User::factory()->create(), [], 'someone');

    expect(FakeImportSource::$seenUsername)->toBe('someone');
});

test('rows by foreign id resolve through the external id resolver', function () {
    Event::fake([PlayRecorded::class]);

    $user = User::factory()->create();
    $movie = MediaItem::factory()->movie()->create(['provider_ref' => '603']);

    $taskRun = runFakeImport($user, [
        new MediaRow(
            MediaSubject::external(MediaKind::Movie, ['tmdb' => '603'], 'The Matrix'),
            plays: [new ImportPlay(new CarbonImmutable('2025-01-10 20:00:00'))],
            rating: new ImportRating(9),
        ),
    ]);

    expect($taskRun->status)->toBe(TaskRunStatus::Success)
        ->and($taskRun->summary)->toContain('Processed 1 entry.', 'plays: 1', 'ratings: 1', 'skipped entries: 0')
        ->and($user->plays()->firstOrFail()->media_item_id)->toBe($movie->id);

    // Everything the run writes is announced as the import it is.
    Event::assertDispatched(PlayRecorded::class, fn (PlayRecorded $event) => $event->source->value() === 'import:fake');
});

test('a title nobody can place is skipped with the ids that were tried', function () {
    Http::fake(['*/find/*' => Http::response(['movie_results' => [], 'tv_results' => []])]);

    $taskRun = runFakeImport(User::factory()->create(), [
        new MediaRow(MediaSubject::external(MediaKind::Movie, ['imdb' => 'tt0000001'], 'Nowhere')),
        new SkipRow('Broken: the source gave up on it.'),
    ]);

    expect($taskRun->summary)->toContain('skipped entries: 2')
        ->and($taskRun->errors)->toBe([
            'Nowhere: no match for imdb tt0000001.',
            'Broken: the source gave up on it.',
        ]);
});

test('a play without a date lands on the release date', function () {
    $user = User::factory()->create();
    MediaItem::factory()->movie()->create(['provider_ref' => '603', 'release_date' => '1999-03-31']);

    runFakeImport($user, [
        new MediaRow(MediaSubject::direct('movie', 'tmdb', '603', 'The Matrix'), plays: [new ImportPlay]),
    ]);

    expect($user->plays()->firstOrFail()->watched_at->toDateString())->toBe('1999-03-31');
});

test('a declared list exists after the run even when empty, and a list named twice is made once', function () {
    $user = User::factory()->create();
    MediaItem::factory()->movie()->create(['provider_ref' => '603']);

    $list = new ImportListRef('Favourites of 1999', isPublic: true);

    runFakeImport($user, [
        new ListRow(new ImportListRef('Empty one')),
        new ListRow($list),
        new MediaRow(MediaSubject::direct('movie', 'tmdb', '603', 'The Matrix'), lists: [new ImportListEntry($list)]),
    ]);

    expect($user->lists()->where('title', 'Empty one')->firstOrFail()->items()->count())->toBe(0)
        ->and($user->lists()->where('title', 'Favourites of 1999')->count())->toBe(1)
        ->and($user->lists()->where('title', 'Favourites of 1999')->firstOrFail()->items()->count())->toBe(1);
});

test('the tasks page names import runs after their source, and the poll does too', function () {
    $user = User::factory()->create();
    TaskRun::queueTask('import-trakt', $user->id);

    $this->actingAs($user)
        ->get('/settings/tasks')
        ->assertInertia(fn (AssertableInertia $page) => $page->where('runs.0.label', 'Import from Trakt.tv'));

    $this->actingAs($user)
        ->getJson(route('settings.tasks.runs'))
        ->assertJsonPath('runs.0.label', 'Import from Trakt.tv')
        ->assertJsonPath('canClear', false);
});

test('a file import that breaks keeps its file for the retry; the last attempt discards it', function () {
    app(ImportSourceRegistry::class)->register(FakeImportSource::CODE, FakeImportSource::class);
    FakeImportSource::$failure = new RuntimeException('The source broke.');

    $path = scratchPath('import');
    $taskRun = TaskRun::queueTask('import-fake', User::factory()->create()->id);
    $job = new RunImport($taskRun, FakeImportSource::CODE, ImportInput::file($path), ImportSelection::everything());

    expect(fn () => app()->call([$job, 'handle']))->toThrow(RuntimeException::class, 'The source broke.');
    expect(is_file($path))->toBeTrue();

    $job->failed(new RuntimeException('The source broke.'));

    expect(is_file($path))->toBeFalse()
        ->and($taskRun->refresh()->status)->toBe(TaskRunStatus::Failure)
        ->and($taskRun->summary)->toBe(__(RunImport::UNEXPECTED_FAILURE));
});

test('a source the service refused or knows nothing about ends the run without a retry, in the user\'s words', function (ExternalServiceException $answer, string $summary) {
    app(ImportSourceRegistry::class)->register(FakeImportSource::CODE, FakeImportSource::class);
    FakeImportSource::$failure = $answer;

    $path = scratchPath('import');
    $taskRun = TaskRun::queueTask('import-fake', User::factory()->create(['locale' => 'pl'])->id);

    app()->call([new RunImport($taskRun, FakeImportSource::CODE, ImportInput::file($path), ImportSelection::everything()), 'handle']);

    expect(is_file($path))->toBeFalse()
        ->and($taskRun->refresh()->status)->toBe(TaskRunStatus::Failure)
        ->and($taskRun->summary)->toBe($summary);
})->with([
    'refused' => [new ServiceRejected('import.service_answered_to', ['service' => 'Simkl', 'status' => 403, 'path' => '/sync/all-items']), 'Simkl odpowiedział 403 na /sync/all-items.'],
    'not found' => [new NotFoundAtService('import.anime_list_no_user', ['service' => 'AniList', 'username' => 'nobody']), 'AniList nie ma użytkownika o nazwie nobody.'],
]);

test('a finished file import discards its file', function () {
    app(ImportSourceRegistry::class)->register(FakeImportSource::CODE, FakeImportSource::class);

    $path = scratchPath('import');
    $taskRun = TaskRun::queueTask('import-fake', User::factory()->create()->id);

    app()->call([new RunImport($taskRun, FakeImportSource::CODE, ImportInput::file($path), ImportSelection::everything()), 'handle']);

    expect(is_file($path))->toBeFalse()
        ->and($taskRun->refresh()->status)->toBe(TaskRunStatus::Success);
});

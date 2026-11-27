<?php

use App\Catalog\ProviderHealth;
use App\Catalog\Providers\Tmdb\TmdbKey;
use App\Catalog\Providers\Tmdb\TmdbProvider;
use App\Catalog\ProviderState;
use App\Models\MaintenanceCheckState;
use App\Models\TaskRun;
use App\Tasks\Maintenance\CheckLevel;
use App\Tasks\Maintenance\Checks\ProviderProbe;
use App\Tasks\Maintenance\Data\CheckLine;
use App\Tasks\Maintenance\MaintenanceChecks;
use App\Tasks\Maintenance\MaintenanceWindow;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->tmdbStatus = 200;
    Http::fake(['api.themoviedb.org/*' => fn () => Http::response(['status_message' => 'Invalid API key: You must be granted a valid key.'], $this->tmdbStatus)]);
});

function runProviderProbe(): MaintenanceCheckState
{
    app(MaintenanceChecks::class)->queueWindow(MaintenanceWindow::Frequent);

    return MaintenanceCheckState::query()->where('code', ProviderProbe::CODE)->sole();
}

/** The line the last run said about TMDB. */
function tmdbLine(MaintenanceCheckState $state): CheckLine
{
    return collect($state->result)->map(CheckLine::fromStored(...))->firstWhere('subject', 'tmdb');
}

function tmdbProbes(): int
{
    return Http::recorded(fn (Request $request): bool => str_contains($request->url(), 'movie/'.TmdbProvider::PROBE_TITLE))->count();
}

test('an idle provider is asked once whether it answers, and its line says it does', function () {
    $line = tmdbLine(runProviderProbe());

    expect(tmdbProbes())->toBe(1)
        ->and($line->level)->toBe(CheckLevel::Ok)
        ->and($line->line->wording())->toBe(__('tasks.provider_answers', ['provider' => 'TMDB']))
        ->and(TaskRun::query()->count())->toBe(0);
});

test('a provider that answered within the quarter hour is not asked', function () {
    app(TmdbProvider::class)->probe();
    $before = tmdbProbes();

    $this->travel(ProviderProbe::QUIET_SECONDS - 1)->seconds();
    runProviderProbe();

    expect(tmdbProbes())->toBe($before);
});

test('a refused key is asked about again only after an hour', function () {
    $this->tmdbStatus = 401;

    runProviderProbe();
    $this->travel(ProviderProbe::REFUSED_PROBE_EVERY_SECONDS - 1)->seconds();
    runProviderProbe();

    expect(tmdbProbes())->toBe(1);

    $this->travel(1)->seconds();
    runProviderProbe();

    expect(tmdbProbes())->toBe(2);
});

test('the admin running the check asks a refused key again at once', function () {
    $this->tmdbStatus = 401;
    runProviderProbe();

    $this->tmdbStatus = 200;
    app(MaintenanceChecks::class)->runNow(ProviderProbe::CODE);

    expect(tmdbProbes())->toBe(2)
        ->and(app(ProviderHealth::class)->of('tmdb')->state)->toBe(ProviderState::Healthy);
});

test('a refused key says what was answered, when it last worked and what to do', function () {
    app(TmdbProvider::class)->probe();
    $answeredAt = now()->toImmutable();
    $this->tmdbStatus = 401;
    $this->travel(ProviderProbe::QUIET_SECONDS)->seconds();

    $line = tmdbLine(runProviderProbe());

    expect($line->level)->toBe(CheckLevel::Problem)
        ->and($line->line->wording())->toBe(__('tasks.provider_refuses_the_key', ['provider' => 'TMDB']))
        ->and(array_map(fn ($detail) => $detail->key, $line->details))->toBe(['tasks.answered_status_reason_at', 'tasks.last_answered_at', 'tasks.put_a_new_key_in_variables'])
        ->and($line->details[0]->params)->toBe(['status' => 401, 'reason' => 'Invalid API key: You must be granted a valid key.'])
        ->and($line->details[1]->moments['time']->eq($answeredAt))->toBeTrue()
        ->and($line->details[2]->params)->toBe(['variables' => TmdbKey::VARIABLE]);
});

test('a refused key is noted in the history once, and so is its return', function () {
    $this->tmdbStatus = 401;
    runProviderProbe();

    expect(TaskRun::query()->sole()->summary)->toBe(__('tasks.the_key_to_provider_stopped_working', ['provider' => 'TMDB']));

    $this->travel(ProviderProbe::QUIET_SECONDS)->seconds();
    runProviderProbe();

    expect(TaskRun::query()->count())->toBe(1);

    $this->tmdbStatus = 200;
    $this->travel(ProviderProbe::REFUSED_PROBE_EVERY_SECONDS)->seconds();
    $line = tmdbLine(runProviderProbe());

    expect($line->level)->toBe(CheckLevel::Ok)
        ->and(TaskRun::query()->latest('id')->first()->summary)->toBe(__('tasks.the_key_to_provider_works_again', ['provider' => 'TMDB']));
});

test('a provider that is not answering warns without a history entry', function () {
    $this->tmdbStatus = 503;

    $line = tmdbLine(runProviderProbe());

    expect($line->level)->toBe(CheckLevel::Warning)
        ->and($line->line->wording())->toBe(__('tasks.provider_is_not_answering', ['provider' => 'TMDB']))
        ->and(app(ProviderHealth::class)->of('tmdb')->state)->toBe(ProviderState::Degraded)
        ->and(TaskRun::query()->count())->toBe(0);
});

test('a probe answered "not found" is an answer, and the run goes on', function () {
    $this->tmdbStatus = 404;

    $state = runProviderProbe();

    expect($state->status->value)->toBe('success')
        ->and(tmdbLine($state)->level)->toBe(CheckLevel::Ok);
});

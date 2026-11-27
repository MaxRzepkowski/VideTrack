<?php

use App\Catalog\ProviderHealth;
use App\Catalog\ProviderKeyRejected;
use App\Catalog\Providers\Tmdb\TmdbKey;
use App\Enums\TaskRunStatus;
use App\Jobs\RunMaintenanceChecks;
use App\Models\MaintenanceCheckState;
use App\Models\User;
use App\Tasks\Maintenance\CheckLevel;
use App\Tasks\Maintenance\Checks\ProviderProbe;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

// A checkout has no public storage link, a development instance does: the
// list is read from a setup that is sound wherever the tests run, and the
// test of the link check sets its own.
beforeEach(fn () => config(['filesystems.links' => [storage_path() => storage_path('app/public')]]));

/** TMDB has turned the instance's key away, as a refused request leaves it. */
function tmdbRefusesTheKey(): void
{
    try {
        app(ProviderHealth::class)->watch('tmdb', fn () => throw new ProviderKeyRejected('TMDB request failed with status 401.'));
    } catch (ProviderKeyRejected) {
        // The state is what the test needs.
    }
}

test('the Instance page is the admin\'s alone', function (string $route) {
    $this->actingAs(User::factory()->create())->get(route($route))->assertNotFound();
    $this->actingAs(User::factory()->admin()->create())->get(route($route))->assertOk();
})->with(['settings.instance.index', 'settings.instance.tasks', 'settings.instance.maintenance', 'settings.instance.checks.index']);

test('an admin\'s tasks are a tab of the Instance page; everyone else keeps theirs', function () {
    $this->actingAs(User::factory()->admin()->create())
        ->get(route('settings.tasks.index'))
        ->assertRedirect(route('settings.instance.tasks'));

    $this->actingAs(User::factory()->create())
        ->get(route('settings.tasks.index'))
        ->assertInertia(fn (Assert $page) => $page->component('settings/Tasks'));
});

test('nothing needing the admin reads as checked when the checks last looked', function () {
    MaintenanceCheckState::factory()->checkedAt(now()->subMinutes(5))->create(['code' => ProviderProbe::CODE]);

    $this->actingAs(User::factory()->admin()->create())
        ->get(route('settings.instance.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/instance/Overview')
            ->has('attention', 0)
            ->where('checkedAt', now()->subMinutes(5)->toIso8601String()));
});

/** The provider check has run and found TMDB refusing the key. */
function checkFindsTmdbRefusingTheKey(): void
{
    tmdbRefusesTheKey();
    Http::fake(['api.themoviedb.org/*' => Http::response([], 401)]);
    dispatch(new RunMaintenanceChecks([ProviderProbe::CODE]));
}

test('a refused provider key the check found needs the admin, since it was refused', function () {
    checkFindsTmdbRefusingTheKey();

    $this->actingAs(User::factory()->admin()->create())
        ->get(route('settings.instance.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('attention', 1)
            ->where('attention.0.level', CheckLevel::Problem->value)
            ->where('attention.0.title', __('tasks.provider_refuses_the_key', ['provider' => 'TMDB']))
            ->where('attention.0.since', now()->toIso8601String())
            ->where('attention.0.details.2.text', __('tasks.put_a_new_key_in_variables', ['variables' => TmdbKey::VARIABLE])));
});

test('the home page counts the problems that need the admin, and only for the admin', function () {
    checkFindsTmdbRefusingTheKey();

    $this->actingAs(User::factory()->admin()->create())
        ->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page->where('attention', 1));

    $this->actingAs(User::factory()->create())
        ->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page->where('attention', 0));
});

test('the maintenance tab lists each check with what its last run found', function () {
    tmdbRefusesTheKey();
    Http::fake(['api.themoviedb.org/*' => Http::response([], 401)]);
    dispatch(new RunMaintenanceChecks([ProviderProbe::CODE]));

    $this->actingAs(User::factory()->admin()->create())
        ->get(route('settings.instance.maintenance'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/instance/Maintenance')
            ->has('checks', 1)
            ->where('checks.0.code', ProviderProbe::CODE)
            ->where('checks.0.label', __('tasks.provider_health'))
            ->where('checks.0.frequency', 'tasks.every_15_minutes')
            ->where('checks.0.status', TaskRunStatus::Success->value)
            ->has('checks.0.lines', 1)
            ->where('checks.0.lines.0.level', CheckLevel::Problem->value)
            ->where('checks.0.lines.0.line.text', __('tasks.provider_refuses_the_key', ['provider' => 'TMDB']))
            ->where('checks.0.lines.0.details.2.text', __('tasks.put_a_new_key_in_variables', ['variables' => TmdbKey::VARIABLE])));
});

test('the moments of a check go to the page to be written in the viewer\'s time', function () {
    Http::fake(['api.themoviedb.org/*' => Http::response([], 401)]);
    dispatch(new RunMaintenanceChecks([ProviderProbe::CODE]));

    $this->actingAs(User::factory()->admin()->create())
        ->get(route('settings.instance.maintenance'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('checks.0.lines.0.details.0.text', __('tasks.answered_status_at', ['status' => 401]))
            ->where('checks.0.lines.0.details.0.moments.time', fn (string $time) => CarbonImmutable::parse($time)->eq(now())));
});

test('an admin can run a check now, once', function () {
    Queue::fake();
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)->post(route('settings.instance.checks.run'), ['code' => ProviderProbe::CODE])->assertRedirect();
    $this->actingAs($admin)->post(route('settings.instance.checks.run'), ['code' => ProviderProbe::CODE])->assertRedirect();

    Queue::assertPushed(RunMaintenanceChecks::class, 1);
    expect(MaintenanceCheckState::query()->where('code', ProviderProbe::CODE)->sole()->status)->toBe(TaskRunStatus::Pending);
});

test('a check this instance does not have cannot be run', function () {
    $this->actingAs(User::factory()->admin()->create())
        ->post(route('settings.instance.checks.run'), ['code' => 'no-such-check'])
        ->assertNotFound();
});

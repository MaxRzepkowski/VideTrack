<?php

use App\Enums\FollowMode;
use App\Enums\TaskRunStatus;
use App\Jobs\RefreshEntityMetadata;
use App\Models\Company;
use App\Models\Follow;
use App\Models\Person;
use App\Models\TaskRun;
use App\Models\User;
use Illuminate\Support\Facades\Http;

test('the sweep rewrites company rows whose provider data moved on', function () {
    Http::fake([
        '*/company/174*' => Http::response(['id' => 174, 'name' => 'Warner Bros. Pictures', 'logo_path' => '/fresh.png']),
        // The page method also lists the studio's filmography; an empty
        // discover page keeps the test on the one field it is about.
        '*/discover/*' => Http::response(['results' => [], 'total_pages' => 1]),
    ]);

    $company = Company::factory()->create([
        'provider' => 'tmdb',
        'kind' => 'company',
        'provider_ref' => '174',
        'name' => 'Warner Bros. Pictures',
        'logo_path' => '/x.png',
    ]);

    RefreshEntityMetadata::queueTask();

    $run = TaskRun::query()->firstOrFail();

    expect($company->fresh()->logo_path)->toBe('/fresh.png')
        ->and($run->name)->toBe('refresh-entities')
        ->and($run->status)->toBe(TaskRunStatus::Success)
        ->and($run->summary)->toContain('Refreshed 1 of 1')
        ->and($run->errors)->toBeNull();
});

test('the sweep covers people a user attached to and leaves credit-only rows to their titles', function () {
    Http::fake(['*/person/6384*' => Http::response(jsonFixture('tmdb/person'))]);

    $user = User::factory()->create();
    $followed = Person::factory()->create([
        'provider' => 'tmdb',
        'provider_ref' => '6384',
        'name' => 'Keanu Reeves',
        'profile_path' => '/gone.jpg',
    ]);
    $fromCredits = Person::factory()->create([
        'provider' => 'tmdb',
        'provider_ref' => '287',
        'name' => 'Brad Pitt',
        'profile_path' => '/also-gone.jpg',
    ]);

    Follow::factory()->create(['user_id' => $user->id, 'person_id' => $followed->id, 'mode' => FollowMode::Follow->value]);

    RefreshEntityMetadata::queueTask();

    expect($followed->fresh()->profile_path)->toBe('/keanu.jpg')
        ->and($fromCredits->fresh()->profile_path)->toBe('/also-gone.jpg');

    Http::assertNotSent(fn ($request) => str_contains($request->url(), '/person/287'));
});

test('an entity the provider no longer serves is noted as kept, never deleted, and nothing went wrong', function () {
    Http::fake([
        '*/company/174*' => Http::response(['status_message' => 'Not found'], 404),
        '*/discover/*' => Http::response(['results' => [], 'total_pages' => 1]),
    ]);

    $company = Company::factory()->create([
        'provider' => 'tmdb',
        'kind' => 'company',
        'provider_ref' => '174',
        'name' => 'Warner Bros. Pictures',
        'logo_path' => '/x.png',
    ]);

    RefreshEntityMetadata::queueTask();

    $run = TaskRun::query()->firstOrFail();

    expect($company->fresh())->not->toBeNull()
        ->and($run->status)->toBe(TaskRunStatus::Success)
        ->and($run->summary)->toContain('Refreshed 0 of 1', '1 kept as they were.')
        ->and($run->errors)->toBeEmpty()
        ->and($run->details)->toHaveCount(1)
        ->and($run->details[0])->toContain('company:174', 'no longer at the provider');
});

test('a creator whose provider is not configured here is noted, and the sweep goes on', function () {
    Http::fake([
        '*/company/174*' => Http::response(['id' => 174, 'name' => 'Warner Bros. Pictures', 'logo_path' => '/fresh.png']),
        '*/discover/*' => Http::response(['results' => [], 'total_pages' => 1]),
    ]);

    // IGDB has no keys in the test environment, so nothing serves its pages.
    Company::factory()->create(['provider' => 'igdb', 'kind' => 'company', 'provider_ref' => '51', 'name' => 'FromSoftware']);
    $tmdb = Company::factory()->create(['provider' => 'tmdb', 'kind' => 'company', 'provider_ref' => '174', 'name' => 'Warner Bros. Pictures', 'logo_path' => '/x.png']);

    RefreshEntityMetadata::queueTask();

    $run = TaskRun::query()->firstOrFail();

    expect($tmdb->fresh()->logo_path)->toBe('/fresh.png')
        ->and($run->status)->toBe(TaskRunStatus::Success)
        ->and($run->summary)->toContain('Refreshed 1 of 2')
        ->and($run->errors)->toBeEmpty()
        ->and($run->details)->toHaveCount(1)
        ->and($run->details[0])->toContain('company:51', 'not configured here');
});

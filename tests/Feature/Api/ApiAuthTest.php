<?php

use App\Models\User;
use Laravel\Sanctum\Sanctum;

test('the api rejects a request without a token', function () {
    $this->getJson('/api/v1/me')
        ->assertUnauthorized()
        ->assertJsonStructure(['message']);
});

test('the api rejects a token that is not ours', function () {
    $this->withHeader('Authorization', 'Bearer not-a-real-token')
        ->getJson('/api/v1/me')
        ->assertUnauthorized();
});

test('a read token gets its own profile', function () {
    $user = User::factory()->create(['username' => 'maks', 'locale' => 'pl']);

    Sanctum::actingAs($user, ['read']);

    $this->getJson('/api/v1/me')
        ->assertOk()
        ->assertJsonPath('data.id', $user->id)
        ->assertJsonPath('data.username', 'maks')
        ->assertJsonPath('data.locale', 'pl')
        ->assertJsonPath('data.email', $user->email);
});

test('a real issued token authenticates over the wire', function () {
    $user = User::factory()->create();
    $token = $user->createToken('curl', ['read'])->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/me')
        ->assertOk()
        ->assertJsonPath('data.id', $user->id);

    expect($user->tokens()->firstOrFail()->last_used_at)->not->toBeNull();
});

test('a revoked token stops working', function () {
    $user = User::factory()->create();
    $token = $user->createToken('curl', ['read'])->plainTextToken;

    $user->tokens()->delete();

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/me')
        ->assertUnauthorized();
});

test('a token without the read ability cannot read', function () {
    Sanctum::actingAs(User::factory()->create(), ['write']);

    $this->getJson('/api/v1/me')->assertForbidden();
});

test('a blocked account cannot use its tokens', function () {
    Sanctum::actingAs(User::factory()->create(['blocked_at' => now()]), ['read']);

    $this->getJson('/api/v1/me')->assertForbidden();
});

test('an unknown api route answers in json, not html', function () {
    Sanctum::actingAs(User::factory()->create(), ['read']);

    $response = $this->getJson('/api/v1/nothing-here')->assertNotFound();

    expect($response->headers->get('Content-Type'))->toContain('application/json');
});

test('a browser hitting an api route still gets json', function () {
    $response = $this->get('/api/v1/me')->assertUnauthorized();

    expect($response->headers->get('Content-Type'))->toContain('application/json');
});

test('the api carries its own rate limit', function () {
    // The limiter counts per minute — without freezing the clock a slow run
    // can cross into the next window and reset the counter mid-test.
    $this->freezeTime();

    $user = User::factory()->create();
    Sanctum::actingAs($user, ['read']);

    foreach (range(1, config('tracker.rate_limits.api')) as $ignored) {
        $this->getJson('/api/v1/me')->assertOk();
    }

    $this->getJson('/api/v1/me')->assertStatus(429);
});

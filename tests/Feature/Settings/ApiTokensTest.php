<?php

use App\Models\User;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Sanctum\PersonalAccessToken;

test('the api settings page renders with an empty list', function () {
    $this->actingAs(User::factory()->create())
        ->get('/settings/api')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/Api')
            ->where('tokens', [])
            ->where('createdToken', null)
        );
});

test('creating a token shows its plaintext exactly once', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post('/settings/api/tokens', ['name' => 'Laptop script'])
        ->assertRedirect()
        ->assertSessionHas('created_token');

    $plain = session('created_token');

    expect($plain)->toBeString()->and($user->tokens()->count())->toBe(1);

    $this->actingAs($user)
        ->get('/settings/api')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('createdToken', $plain)
            ->where('tokens.0.name', 'Laptop script')
            ->where('tokens.0.abilities', ['read'])
            ->where('tokens.0.lastUsedAt', null)
        );

    // A second visit no longer carries it — the flash is gone and the
    // database only ever kept the hash.
    $this->actingAs($user)
        ->get('/settings/api')
        ->assertInertia(fn (Assert $page) => $page->where('createdToken', null));
});

test('the write ability is opt-in', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->post('/settings/api/tokens', ['name' => 'Reader'])->assertRedirect();
    $this->actingAs($user)->post('/settings/api/tokens', ['name' => 'Writer', 'write' => true])->assertRedirect();

    expect($user->tokens()->where('name', 'Reader')->firstOrFail()->abilities)->toBe(['read'])
        ->and($user->tokens()->where('name', 'Writer')->firstOrFail()->abilities)->toBe(['read', 'write']);
});

test('a token can be given an expiry date', function () {
    $user = User::factory()->create();
    $date = CarbonImmutable::today()->addDays(30)->toDateString();

    $this->actingAs($user)
        ->post('/settings/api/tokens', ['name' => 'Temporary', 'expires_at' => $date])
        ->assertRedirect();

    expect($user->tokens()->firstOrFail()->expires_at?->toDateString())->toBe($date);
});

test('a token lasts until the picked day ends on the user own clock', function () {
    $user = User::factory()->create(['preferences' => ['timezone' => 'Europe/Warsaw']]);

    $this->actingAs($user)
        ->post('/settings/api/tokens', ['name' => 'Temporary', 'expires_at' => '2026-12-01'])
        ->assertRedirect();

    expect($user->tokens()->firstOrFail()->expires_at?->utc()->toDateTimeString())->toBe('2026-12-01 22:59:59');
});

test('a token ends at the end of the picked day whatever zone the app keeps time in', function () {
    config(['app.timezone' => 'America/New_York']);
    date_default_timezone_set('America/New_York');
    $user = User::factory()->create(['preferences' => ['timezone' => 'Europe/Warsaw']]);

    $this->actingAs($user)
        ->post(route('settings.api-tokens.store'), ['name' => 'Temporary', 'expires_at' => '2026-12-01'])
        ->assertRedirect();

    expect($user->tokens()->firstOrFail()->expires_at?->utc()->toDateTimeString())->toBe('2026-12-01 22:59:59');
});

test('a token expiry is a day as the date field sends it', function () {
    $this->actingAs(User::factory()->create())
        ->post(route('settings.api-tokens.store'), ['name' => 'Temporary', 'expires_at' => '12/01/2027'])
        ->assertSessionHasErrors('expires_at');
});

test("a token expires after the viewer's own today", function () {
    // Noon in Greenwich is two in the morning of the next day on Kiritimati.
    $this->actingAs(User::factory()->create(['preferences' => ['timezone' => 'Pacific/Kiritimati']]))
        ->post(route('settings.api-tokens.store'), ['name' => 'Temporary', 'expires_at' => '2026-10-11'])
        ->assertSessionHasErrors('expires_at');
});

test('a token needs a name and cannot expire in the past', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post('/settings/api/tokens', ['name' => ''])
        ->assertSessionHasErrors('name');

    $this->actingAs($user)
        ->post('/settings/api/tokens', [
            'name' => 'Stale',
            'expires_at' => CarbonImmutable::yesterday()->toDateString(),
        ])
        ->assertSessionHasErrors('expires_at');

    expect($user->tokens()->count())->toBe(0);
});

test('revoking a token stops it working', function () {
    $user = User::factory()->create();
    $plain = $user->createToken('Laptop script', ['read'])->plainTextToken;
    $id = $user->tokens()->firstOrFail()->id;

    $this->withHeader('Authorization', "Bearer {$plain}")->getJson('/api/v1/me')->assertOk();

    // The bearer request above switched the default guard to sanctum; a
    // browser request runs in its own process on the web guard.
    $this->actingAs($user, 'web')->delete("/settings/api/tokens/{$id}")->assertRedirect();

    expect($user->tokens()->count())->toBe(0);

    // A script's next call carries no browser session: drop the session user.
    $this->app['auth']->forgetGuards();

    $this->withHeader('Authorization', "Bearer {$plain}")->getJson('/api/v1/me')->assertUnauthorized();
});

test('somebody else token cannot be revoked', function () {
    $stranger = User::factory()->create();
    $stranger->createToken('Theirs', ['read']);
    $id = $stranger->tokens()->firstOrFail()->id;

    $this->actingAs(User::factory()->create())
        ->delete("/settings/api/tokens/{$id}")
        ->assertNotFound();

    expect($stranger->tokens()->count())->toBe(1);
});

test('the api settings page needs a login', function () {
    $this->get('/settings/api')->assertRedirect('/login');
});

test('deleting the account takes its tokens with it', function () {
    $user = User::factory()->create();
    $plain = $user->createToken('Laptop script', ['read'])->plainTextToken;
    $survivor = User::factory()->create();
    $survivor->createToken('Not mine to lose', ['read']);

    // `personal_access_tokens` is polymorphic, so no foreign key cascade
    // reaches it — without the model hook these rows would outlive the
    // account that made them.
    $this->actingAs($user)
        ->delete('/settings/profile', ['password' => 'password'])
        ->assertRedirect('/');

    expect(PersonalAccessToken::query()->where('tokenable_id', $user->id)->count())->toBe(0)
        ->and(PersonalAccessToken::query()->where('tokenable_id', $survivor->id)->count())->toBe(1);

    $this->withHeader('Authorization', "Bearer {$plain}")
        ->getJson('/api/v1/me')
        ->assertUnauthorized();
});

test('the delete command takes the tokens too', function () {
    $user = User::factory()->create();
    $user->createToken('Laptop script', ['read']);

    $this->artisan('vide:user:delete', ['user' => $user->username, '--force' => true])
        ->assertSuccessful();

    expect(PersonalAccessToken::query()->where('tokenable_id', $user->id)->count())->toBe(0);
});

test('the page that shows a new token keeps it encrypted in the browser history, and signing out clears it', function () {
    $user = User::factory()->create();

    expect($this->actingAs($user)->get('/settings/api')->viewData('page')['encryptHistory'])->toBeTrue();

    // The next page the browser gets is told to forget what history holds.
    $this->actingAs($user)->post(route('logout'));

    expect($this->get(route('login'))->viewData('page')['clearHistory'])->toBeTrue();
});

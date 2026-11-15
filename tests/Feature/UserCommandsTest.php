<?php

use App\Enums\UserRole;
use App\Models\Play;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;

test('vide:user:list prints all accounts', function () {
    $user = User::factory()->create();

    $this->artisan('vide:user:list')
        ->expectsOutputToContain($user->username)
        ->assertSuccessful();
});

test('commands fail cleanly for an unknown user', function () {
    $this->artisan('vide:user:promote', ['user' => 'nobody'])
        ->expectsOutputToContain('No user with username or e-mail "nobody".')
        ->assertFailed();
});

test('vide:user:reset-password sets the given password', function () {
    $user = User::factory()->create();

    $this->artisan('vide:user:reset-password', ['user' => $user->username, '--password' => 'new-secret-123'])
        ->assertSuccessful();

    expect(Hash::check('new-secret-123', $user->refresh()->password))->toBeTrue();
});

test('vide:user:reset-password revokes the account\'s API tokens', function () {
    $user = User::factory()->create();
    $user->createToken('script');

    $this->artisan('vide:user:reset-password', ['user' => $user->username, '--password' => 'new-secret-123'])
        ->assertSuccessful();

    expect($user->tokens()->count())->toBe(0);
});

test('vide:user:reset-password without --password generates and prints one', function () {
    $user = User::factory()->create(['password' => 'old-password-123']);

    $this->artisan('vide:user:reset-password', ['user' => $user->email])
        ->expectsOutputToContain('has been reset to:')
        ->assertSuccessful();

    expect(Hash::check('old-password-123', $user->refresh()->password))->toBeFalse();
});

test('vide:user:reset-password logs the user out of the sessions they already have', function () {
    $user = User::factory()->create();

    $this->post('/login', ['email' => $user->email, 'password' => 'password']);
    $this->get('/dashboard')->assertOk();

    $this->artisan('vide:user:reset-password', ['user' => $user->username, '--password' => 'new-secret-123'])
        ->assertSuccessful();

    // The next request comes from a fresh process: drop the user the guard
    // still holds in memory from the requests above.
    $this->app['auth']->forgetGuards();

    $this->get('/dashboard')->assertRedirect('/login');
    $this->assertGuest();
});

test('vide:user:reset-password refuses a password the sign-up form would refuse', function () {
    $user = User::factory()->create(['password' => 'old-password-123']);

    $this->artisan('vide:user:reset-password', ['user' => $user->username, '--password' => 'a'])
        ->assertFailed();

    expect(Hash::check('old-password-123', $user->refresh()->password))->toBeTrue();
});

test('vide:user:block prevents login and --unblock restores it', function () {
    $user = User::factory()->create(['password' => 'password']);

    $this->artisan('vide:user:block', ['user' => $user->username])->assertSuccessful();

    expect($user->refresh()->isBlocked())->toBeTrue();

    $this->post('/login', ['email' => $user->email, 'password' => 'password'])
        ->assertSessionHasErrors('email');
    $this->assertGuest();

    $this->artisan('vide:user:block', ['user' => $user->username, '--unblock' => true])->assertSuccessful();

    expect($user->refresh()->isBlocked())->toBeFalse();

    $this->post('/login', ['email' => $user->email, 'password' => 'password'])
        ->assertRedirect(route('dashboard', absolute: false));
});

test('a user blocked mid-session is logged out on the next request', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/dashboard')->assertOk();

    $user->forceFill(['blocked_at' => now()])->save();

    $this->actingAs($user)->get('/dashboard')->assertRedirect('/login');
    $this->assertGuest();
});

test('vide:user:delete removes the account and its state after confirmation', function () {
    $user = User::factory()->create();
    Play::factory()->create([
        'user_id' => $user->id,
        'media_item_id' => makeShowTree([1 => ['2020-01-01']])->children()->first()->children()->first()->id,
        'watched_at' => now(),
    ]);

    $this->artisan('vide:user:delete', ['user' => $user->username])
        ->expectsConfirmation("Delete {$user->username} <{$user->email}> and all their data? This cannot be undone.", 'yes')
        ->assertSuccessful();

    expect(User::find($user->id))->toBeNull()
        ->and(Play::where('user_id', $user->id)->count())->toBe(0);
});

test('vide:user:delete takes the API tokens and the avatar file along, like the Danger Zone', function () {
    Storage::fake('public');
    $user = User::factory()->create();
    $user->createToken('script');
    Storage::disk('public')->put('avatars/me.webp', 'image');
    $user->forceFill(['avatar_path' => 'avatars/me.webp'])->save();

    $this->artisan('vide:user:delete', ['user' => $user->username, '--force' => true])->assertSuccessful();

    expect(DB::table('personal_access_tokens')->count())->toBe(0);
    Storage::disk('public')->assertMissing('avatars/me.webp');
});

test('vide:user:block ends the sessions kept in the database at once', function () {
    config(['session.driver' => 'database']);
    $user = User::factory()->create();
    DB::table('sessions')->insert(['id' => 'open-tab', 'user_id' => $user->id, 'payload' => '', 'last_activity' => now()->timestamp]);

    $this->artisan('vide:user:block', ['user' => $user->username])->assertSuccessful();

    expect(DB::table('sessions')->where('user_id', $user->id)->exists())->toBeFalse();
});

test('vide:user:delete aborts without confirmation', function () {
    $user = User::factory()->create();

    $this->artisan('vide:user:delete', ['user' => $user->username])
        ->expectsConfirmation("Delete {$user->username} <{$user->email}> and all their data? This cannot be undone.", 'no')
        ->assertFailed();

    expect(User::find($user->id))->not->toBeNull();
});

test('vide:user:promote grants and --demote revokes the admin role', function () {
    $user = User::factory()->create();

    $this->artisan('vide:user:promote', ['user' => $user->username])->assertSuccessful();
    expect($user->refresh()->role)->toBe(UserRole::Admin);

    $this->artisan('vide:user:promote', ['user' => $user->username, '--demote' => true])->assertSuccessful();
    expect($user->refresh()->role)->toBe(UserRole::User);
});

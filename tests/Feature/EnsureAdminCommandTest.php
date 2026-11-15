<?php

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

test('it skips when ADMIN_EMAIL is not configured', function () {
    config(['tracker.admin_email' => null]);

    $this->artisan('app:ensure-admin')->assertSuccessful();

    expect(User::count())->toBe(0);
});

test('it creates the admin account when missing', function () {
    config(['tracker.admin_email' => 'admin@example.com', 'tracker.admin_password' => 'secret-password']);

    $this->artisan('app:ensure-admin')->assertSuccessful();

    $user = User::where('email', 'admin@example.com')->firstOrFail();
    expect($user->role)->toBe(UserRole::Admin)
        ->and(Hash::check('secret-password', $user->password))->toBeTrue()
        // Made like any account: its watchlist and feed token are there.
        ->and($user->watchlist()->exists)->toBeTrue()
        ->and(User::query()->withCalendarToken($user->calendarToken())->value('id'))->toBe($user->id);
});

test('it fails when creating without ADMIN_PASSWORD', function () {
    config(['tracker.admin_email' => 'admin@example.com', 'tracker.admin_password' => null]);

    $this->artisan('app:ensure-admin')->assertFailed();

    expect(User::count())->toBe(0);
});

test('it promotes an existing account and does not overwrite its password', function () {
    $user = User::factory()->create(['email' => 'admin@example.com', 'password' => Hash::make('user-chosen')]);
    config(['tracker.admin_email' => 'admin@example.com', 'tracker.admin_password' => 'env-password']);

    $this->artisan('app:ensure-admin')->assertSuccessful();
    $this->artisan('app:ensure-admin')->assertSuccessful();

    $user->refresh();
    expect($user->role)->toBe(UserRole::Admin)
        ->and(Hash::check('user-chosen', $user->password))->toBeTrue();
});

test('it refuses an ADMIN_PASSWORD the sign-up form would refuse', function () {
    config(['tracker.admin_email' => 'admin@example.com', 'tracker.admin_password' => 'short']);

    $this->artisan('app:ensure-admin')->assertFailed();

    expect(User::query()->where('email', 'admin@example.com')->exists())->toBeFalse();
});

test('the admin gets a free username made from the address, never a taken one', function () {
    User::factory()->create(['username' => 'admin']);
    config(['tracker.admin_email' => 'a@example.com', 'tracker.admin_password' => 'secret-password']);

    $this->artisan('app:ensure-admin')->assertSuccessful();

    // "a" is too short to stand alone; "admin" is taken.
    expect(User::query()->where('email', 'a@example.com')->value('username'))->toBe('admin_2');
});

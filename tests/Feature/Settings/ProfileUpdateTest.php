<?php

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

test('profile page is displayed', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->get('/settings/profile');

    $response->assertOk();
});

test('profile information can be updated', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->patch('/settings/profile', [
            'name' => 'Test User',
            'username' => 'test_user',
            'email' => 'test@example.com',
            'current_password' => 'password',
            'bio' => 'A short bio',
            'is_private' => true,
        ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect('/settings/profile');

    $user->refresh();

    $this->assertSame('Test User', $user->name);
    $this->assertSame('test_user', $user->username);
    $this->assertSame('test@example.com', $user->email);
    $this->assertSame('A short bio', $user->bio);
    $this->assertTrue($user->is_private);
});

test('a new address takes the password, an unchanged one nothing', function () {
    $user = User::factory()->create(['email' => 'alex@example.com']);
    $this->actingAs($user)->from(route('settings.profile.edit'));
    $profile = fn (array $fields) => $this->patch(route('settings.profile.update'), [
        'name' => $user->name, 'username' => $user->username, 'email' => 'alex@example.com', ...$fields,
    ]);

    $profile(['bio' => 'Only the bio'])->assertSessionHasNoErrors();

    $profile(['email' => 'someone@example.com'])->assertSessionHasErrors('current_password');
    $profile(['email' => 'someone@example.com', 'current_password' => 'not it'])->assertSessionHasErrors('current_password');
    expect($user->refresh()->email)->toBe('alex@example.com');

    $profile(['email' => 'someone@example.com', 'current_password' => 'password'])->assertSessionHasNoErrors();
    expect($user->refresh()->email)->toBe('someone@example.com')
        ->and($user->bio)->toBe('Only the bio');
});

test('an account without a password confirms a new address with its username', function () {
    $user = User::factory()->withoutPassword()->create(['email' => 'alex@example.com', 'username' => 'alex']);
    $this->actingAs($user)->from(route('settings.profile.edit'));
    $profile = fn (array $fields) => $this->patch(route('settings.profile.update'), [
        'name' => $user->name, 'username' => 'alex', 'email' => 'someone@example.com', ...$fields,
    ]);

    $profile(['confirm_username' => 'somebody'])->assertSessionHasErrors(['confirm_username' => __('settings.username_does_not_match')]);
    expect($user->refresh()->email)->toBe('alex@example.com');

    $profile(['confirm_username' => 'alex'])->assertSessionHasNoErrors();
    expect($user->refresh()->email)->toBe('someone@example.com');
});

test('username cannot be taken by someone else', function () {
    $user = User::factory()->create();
    User::factory()->create(['username' => 'taken_name']);

    $response = $this
        ->actingAs($user)
        ->from('/settings/profile')
        ->patch('/settings/profile', [
            'name' => $user->name,
            'username' => 'taken_name',
            'email' => $user->email,
        ]);

    $response->assertSessionHasErrors('username');
});

test('avatar is saved and removed with the profile form', function () {
    Storage::fake('public');

    $user = User::factory()->create();

    $base = [
        'name' => $user->name,
        'username' => $user->username,
        'email' => $user->email,
    ];

    $this->actingAs($user)
        ->patch('/settings/profile', [
            ...$base,
            'avatar' => UploadedFile::fake()->image('me.png', 200, 200),
        ])
        ->assertSessionHasNoErrors();

    $path = $user->refresh()->avatar_path;
    $this->assertNotNull($path);
    Storage::disk('public')->assertExists($path);

    // Published re-encoded: a square WebP, nothing of the upload's metadata.
    $this->assertStringEndsWith('.webp', $path);
    $this->assertSame([200, 200], array_slice((array) getimagesizefromstring((string) Storage::disk('public')->get($path)), 0, 2));

    $this->actingAs($user)
        ->patch('/settings/profile', [
            ...$base,
            'remove_avatar' => true,
        ])
        ->assertSessionHasNoErrors();

    $this->assertNull($user->refresh()->avatar_path);
    Storage::disk('public')->assertMissing($path);
});

test('replacing the avatar deletes the previous file', function () {
    Storage::fake('public');

    $user = User::factory()->create();

    $base = [
        'name' => $user->name,
        'username' => $user->username,
        'email' => $user->email,
    ];

    $this->actingAs($user)->patch('/settings/profile', [
        ...$base,
        'avatar' => UploadedFile::fake()->image('first.png'),
    ]);

    $first = $user->refresh()->avatar_path;

    $this->actingAs($user)->patch('/settings/profile', [
        ...$base,
        'avatar' => UploadedFile::fake()->image('second.png'),
    ]);

    $second = $user->refresh()->avatar_path;

    $this->assertNotSame($first, $second);
    Storage::disk('public')->assertMissing($first);
    Storage::disk('public')->assertExists($second);
});

test('user can delete their account', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->delete('/settings/profile', [
            'password' => 'password',
        ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect('/');

    $this->assertGuest();
    $this->assertNull($user->fresh());
});

test('correct password must be provided to delete account', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->from('/settings/profile')
        ->delete('/settings/profile', [
            'password' => 'wrong-password',
        ]);

    $response
        ->assertSessionHasErrors('password')
        ->assertRedirect('/settings/profile');

    $this->assertNotNull($user->fresh());
});

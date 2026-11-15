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

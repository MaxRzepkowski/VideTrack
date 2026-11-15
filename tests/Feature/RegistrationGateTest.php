<?php

use App\Models\User;

test('registration routes return 404 when registration is disabled', function () {
    config(['tracker.registration_enabled' => false]);

    $this->get('/register')->assertNotFound();

    $this->post('/register', registrationForm())->assertNotFound();

    expect(User::where('email', 'test@example.com')->exists())->toBeFalse();
});

test('login screen hides the sign up link when registration is disabled', function () {
    config(['tracker.registration_enabled' => false]);

    $this->get('/login')
        ->assertInertia(fn ($page) => $page->where('registrationEnabled', false));
});

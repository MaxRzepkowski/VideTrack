<?php

/**
 * What the sign-up form sends for a valid new account; a test overrides the
 * field it is about.
 *
 * @param  array<string, string>  $overrides
 * @return array<string, string>
 */
function registrationForm(array $overrides = []): array
{
    return [
        'name' => 'Test User',
        'username' => 'test_user',
        'email' => 'test@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
        ...$overrides,
    ];
}

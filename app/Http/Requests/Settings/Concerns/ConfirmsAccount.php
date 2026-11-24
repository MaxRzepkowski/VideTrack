<?php

namespace App\Http\Requests\Settings\Concerns;

use App\Accounts\PasswordLogin;
use App\Models\User;
use Illuminate\Validation\Rule;

/**
 * How someone confirms it is them before a change that hands the account
 * over or cannot be undone: with their password where it is their way in,
 * otherwise by typing their username. Each form names the two fields.
 */
trait ConfirmsAccount
{
    /**
     * @return array<string, list<mixed>>
     */
    private function confirmationRules(User $user, string $passwordField, string $usernameField): array
    {
        return app(PasswordLogin::class)->isWayInFor($user)
            ? [$passwordField => ['required', 'current_password']]
            : [$usernameField => ['required', 'string', Rule::in([$user->username])]];
    }

    /**
     * @return array<string, string>
     */
    private function confirmationMessages(string $usernameField): array
    {
        return ["{$usernameField}.in" => trans('settings.username_does_not_match')];
    }
}

<?php

namespace App\Accounts;

use App\Enums\UserRole;
use App\Models\User;

/**
 * Who may sign up. The operator decides with REGISTRATION_ENABLED; left
 * unset, sign-up is open until the first account exists and closed after,
 * so a fresh instance can be set up from the browser and one exposed to
 * the internet does not take accounts from strangers. The first account
 * runs the instance, so it becomes its administrator.
 */
final readonly class Registration
{
    public function isOpen(): bool
    {
        $setting = config('tracker.registration_enabled');

        return is_bool($setting) ? $setting : ! $this->hasAccounts();
    }

    public function roleOfNextAccount(): UserRole
    {
        return $this->hasAccounts() ? UserRole::User : UserRole::Admin;
    }

    private function hasAccounts(): bool
    {
        return User::query()->exists();
    }
}

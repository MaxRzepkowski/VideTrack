<?php

namespace App\Actions\Users;

use App\Accounts\Registration;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * An account someone signs up for. Its role is decided in the transaction
 * that creates it, so two sign-ups on a fresh instance cannot both see an
 * empty instance and both become its administrator.
 */
final readonly class RegisterAccount
{
    public function __construct(
        private CreateAccount $createAccount,
        private Registration $registration,
    ) {}

    public function execute(string $name, string $username, string $email, string $password): User
    {
        return DB::transaction(fn (): User => $this->createAccount->execute(
            $name,
            $username,
            $email,
            $password,
            $this->registration->roleOfNextAccount(),
        ));
    }
}

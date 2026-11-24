<?php

namespace App\Console\Commands;

use App\Accounts\PasswordLogin;
use App\Accounts\Username;
use App\Actions\Users\CreateAccount;
use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

class EnsureAdminCommand extends Command
{
    protected $signature = 'app:ensure-admin';

    protected $description = 'Create the bootstrap admin account from ADMIN_EMAIL / ADMIN_PASSWORD if it does not exist (idempotent, safe to run on every container start); warns when the address belongs to an account without the admin role, and when PASSWORD_LOGIN_ENABLED=false is ignored for lack of an identity provider';

    public function handle(CreateAccount $createAccount, PasswordLogin $passwordLogin): int
    {
        // Said on every start: the operator asked for something the instance
        // does not do, and nothing else would tell them.
        if ($passwordLogin->isKeptForLackOfProviders()) {
            $this->warn('PASSWORD_LOGIN_ENABLED=false is ignored: no identity provider is configured, so passwords still sign in.');
        }

        $email = config('tracker.admin_email');
        $password = config('tracker.admin_password');

        if (! is_string($email) || blank($email)) {
            $this->info('ADMIN_EMAIL is not set, skipping admin bootstrap.');

            return self::SUCCESS;
        }

        $user = User::where('email', $email)->first();

        if ($user === null) {
            if (! is_string($password) || blank($password)) {
                $this->error('ADMIN_PASSWORD must be set to create the admin account.');

                return self::FAILURE;
            }

            // The same rule as the sign-up form: an instance's first account
            // is no place for a weaker password.
            $validator = Validator::make(['password' => $password], ['password' => ['required', Password::defaults()]]);

            if ($validator->fails()) {
                $this->error('ADMIN_PASSWORD: '.$validator->errors()->first('password'));

                return self::FAILURE;
            }

            $name = str($email)->before('@')->toString();

            $createAccount->execute(
                name: $name,
                username: Username::firstFree($name, 'admin'),
                email: $email,
                password: $password,
                role: UserRole::Admin,
            );

            $this->info("Admin account {$email} created.");

            return self::SUCCESS;
        }

        // Never promoted from here: nobody confirms the address an account
        // signed up with, so whoever registered it would become the
        // administrator at the next start.
        if (! $user->isAdmin()) {
            $this->warn("ADMIN_EMAIL {$email} belongs to an account without the admin role; it is left as it is. Make it an administrator with: vide:user:promote {$email}");
        } else {
            $this->info("Admin account {$email} already present.");
        }

        return self::SUCCESS;
    }
}

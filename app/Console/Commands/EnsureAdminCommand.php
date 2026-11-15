<?php

namespace App\Console\Commands;

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

    protected $description = 'Create the bootstrap admin account from ADMIN_EMAIL / ADMIN_PASSWORD if it does not exist and guarantee it has the admin role (idempotent, safe to run on every container start)';

    public function handle(CreateAccount $createAccount): int
    {
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
                username: $this->availableUsername($name),
                email: $email,
                password: $password,
                role: UserRole::Admin,
            );

            $this->info("Admin account {$email} created.");

            return self::SUCCESS;
        }

        if (! $user->isAdmin()) {
            $user->forceFill(['role' => UserRole::Admin])->save();
            $this->info("Existing account {$email} promoted to admin.");
        } else {
            $this->info("Admin account {$email} already present.");
        }

        return self::SUCCESS;
    }

    private function availableUsername(string $base): string
    {
        // Room left for a numbered suffix, so the name stays within the rule.
        $slug = str($base)->slug('_')->limit(Username::MAX - 4, '')->toString();

        if (strlen($slug) < Username::MIN) {
            $slug = 'admin';
        }

        $candidate = $slug;
        $suffix = 1;

        while (User::where('username', $candidate)->exists()) {
            $candidate = $slug.'_'.(++$suffix);
        }

        return $candidate;
    }
}

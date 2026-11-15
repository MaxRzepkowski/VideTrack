<?php

namespace App\Console\Commands;

use App\Actions\Users\RecoverAccount;
use App\Console\Commands\Concerns\ResolvesUsers;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

class UserResetPasswordCommand extends Command
{
    use ResolvesUsers;

    protected $signature = 'vide:user:reset-password
        {user : Username or e-mail}
        {--password= : New password; omitted = a random one is generated and printed}';

    protected $description = 'Reset a user\'s password, ending their sessions and revoking their API tokens';

    public function handle(RecoverAccount $recover): int
    {
        $user = $this->resolveUser((string) $this->argument('user'));

        if ($user === null) {
            return self::FAILURE;
        }

        $password = (string) ($this->option('password') ?: Str::password(16));

        // The same rule as the sign-up and reset forms.
        $validator = Validator::make(['password' => $password], ['password' => ['required', Password::defaults()]]);

        if ($validator->fails()) {
            $this->error($validator->errors()->first('password'));

            return self::FAILURE;
        }

        $recover->execute($user, $password);

        if ($this->option('password')) {
            $this->info("Password of {$user->username} has been reset.");
        } else {
            $this->info("Password of {$user->username} has been reset to: {$password}");
        }

        return self::SUCCESS;
    }
}

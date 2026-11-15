<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ResolvesUsers;
use App\Library\LibraryProjector;
use App\Models\User;
use Illuminate\Console\Command;
use Throwable;

/**
 * Works every library out again from its history. The projection is only a
 * shortcut of the history, so this is always safe — the remedy for a
 * projection a crash left behind its history.
 */
class LibraryRebuildCommand extends Command
{
    use ResolvesUsers;

    protected $signature = 'vide:library:rebuild
        {user? : Username or e-mail; every account when left out}';

    protected $description = 'Work the library state out again from the play history';

    public function handle(LibraryProjector $projector): int
    {
        $identifier = $this->argument('user');

        if ($identifier !== null) {
            $user = $this->resolveUser((string) $identifier);

            if ($user === null) {
                return self::FAILURE;
            }

            $projector->rebuild($user);
            $this->info("The library of {$user->username} has been rebuilt.");

            return self::SUCCESS;
        }

        $count = 0;
        $failed = 0;

        foreach (User::query()->lazyById() as $user) {
            // One account's broken history must not leave every account
            // after it unprojected: report it and go on.
            try {
                $projector->rebuild($user);
                $count++;
            } catch (Throwable $e) {
                report($e);
                $failed++;
                $this->error("The library of {$user->username} could not be rebuilt.");
            }
        }

        $this->info("Rebuilt {$count} libraries.");

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}

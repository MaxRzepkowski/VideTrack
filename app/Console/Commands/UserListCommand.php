<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

class UserListCommand extends Command
{
    protected $signature = 'vide:user:list';

    protected $description = 'List all user accounts';

    public function handle(): int
    {
        $rows = User::query()
            ->orderBy('id')
            ->get()
            ->map(fn (User $user) => [
                $user->id,
                $user->username,
                $user->email,
                $user->role->value,
                $user->isBlocked() ? 'yes' : 'no',
                $user->created_at?->toDateString(),
            ]);

        $this->table(['ID', 'Username', 'E-mail', 'Role', 'Blocked', 'Created'], $rows->all());

        return self::SUCCESS;
    }
}

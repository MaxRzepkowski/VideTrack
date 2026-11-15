<?php

namespace App\Actions\Users;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Writes some of the user's preferences and leaves the rest as stored. The
 * row is read again inside the write: the page saves a section while the
 * browser reports its timezone in the background, and a merge into the copy
 * read at the start of either request would put back what the other wrote.
 */
final readonly class UpdatePreferences
{
    /** A write that lost the race to another one reads the row again and goes again. */
    private const ATTEMPTS = 3;

    /**
     * @param  array<string, mixed>  $changes  validated, keyed as stored
     */
    public function execute(User $user, array $changes): void
    {
        $preferences = DB::transaction(function () use ($user, $changes) {
            $stored = User::query()->findOrFail($user->id);
            $stored->preferences = $stored->preferences->merge($changes);
            $stored->save();

            return $stored->preferences;
        }, self::ATTEMPTS);

        $user->preferences = $preferences;
        $user->syncOriginalAttribute('preferences');
    }
}

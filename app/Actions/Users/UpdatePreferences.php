<?php

namespace App\Actions\Users;

use App\Accounts\Preferences\UserPreferences;
use App\Catalog\MediaTypeRegistry;
use App\Models\User;
use App\Support\Payload;
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

    public function __construct(private MediaTypeRegistry $types) {}

    /**
     * @param  array<string, mixed>  $changes  validated, keyed as stored
     */
    public function execute(User $user, array $changes): void
    {
        $preferences = DB::transaction(function () use ($user, $changes) {
            $stored = User::query()->findOrFail($user->id);
            $stored->preferences = $stored->preferences->merge($this->keepingAbsentKinds($stored->preferences, $changes));
            $stored->save();

            return $stored->preferences;
        }, self::ATTEMPTS);

        $user->preferences = $preferences;
        $user->syncOriginalAttribute('preferences');
    }

    /**
     * Own sources come whole from a page that offers only the kinds of title
     * the instance has: those of a kind whose module is off stay as stored,
     * for when it comes back.
     *
     * @param  array<string, mixed>  $changes
     * @return array<string, mixed>
     */
    private function keepingAbsentKinds(UserPreferences $stored, array $changes): array
    {
        if (! array_key_exists(UserPreferences::TYPE_SOURCES, $changes)) {
            return $changes;
        }

        $absent = array_diff_key(Payload::named($stored->toStored()[UserPreferences::TYPE_SOURCES] ?? null), array_flip($this->types->rootCodes()));

        return [...$changes, UserPreferences::TYPE_SOURCES => [...$absent, ...Payload::named($changes[UserPreferences::TYPE_SOURCES])]];
    }
}

<?php

namespace App\Accounts;

use App\Models\User;
use App\Support\Locales;

/**
 * The UI languages somebody here reads in: every account's own, the default a
 * guest and a new account get, and the fallback a missing text reads in.
 * Work a task repeats per language is done for these alone, so a language the
 * UI gains costs nothing until somebody picks it.
 */
final readonly class LocalesInUse
{
    /**
     * In the order the UI lists its languages.
     *
     * @return list<string>
     */
    public function all(): array
    {
        $chosen = User::query()->select('locale')->distinct()->whereNotNull('locale')->get()
            ->map(fn (User $user): ?string => $user->locale)
            ->all();

        $inUse = [config()->string('tracker.default_locale'), Locales::fallback(), ...$chosen];

        return array_values(array_filter(Locales::supported(), fn (string $locale): bool => in_array($locale, $inUse, true)));
    }
}

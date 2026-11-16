<?php

namespace App\Accounts;

use App\Models\User;

/**
 * What a username may look like — the one rule behind sign-up and the
 * profile form, and the free name an account made without the form (the
 * admin bootstrap, a first sign-in through an identity provider) is given.
 * It names the profile's address, so it stays lower-case letters, digits and
 * underscores.
 */
final class Username
{
    public const MIN = 3;

    public const MAX = 30;

    /** Characters a made-up name leaves free for a numbered suffix ("_12"). */
    public const SUFFIX_ROOM = 4;

    /**
     * @return list<string>
     */
    public static function rules(): array
    {
        return ['string', 'min:'.self::MIN, 'max:'.self::MAX, 'regex:/^[a-z0-9_]+$/'];
    }

    /**
     * The first name nobody has yet, made from $wanted (an e-mail's local
     * part, a handle elsewhere) and numbered when taken; from $fallback when
     * too little of $wanted fits the rule.
     */
    public static function firstFree(string $wanted, string $fallback): string
    {
        $base = self::from($wanted) ?? $fallback;
        $candidate = $base;
        $suffix = 1;

        while (User::query()->where('username', $candidate)->exists()) {
            $candidate = $base.'_'.(++$suffix);
        }

        return $candidate;
    }

    /**
     * $wanted within the rule, with room left for a numbered suffix; null
     * when fewer than the minimum characters survive.
     */
    public static function from(string $wanted): ?string
    {
        $slug = str($wanted)->slug('_')->limit(self::MAX - self::SUFFIX_ROOM, '')->toString();

        return strlen($slug) < self::MIN ? null : $slug;
    }
}

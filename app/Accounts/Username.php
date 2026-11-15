<?php

namespace App\Accounts;

/**
 * What a username may look like — the one rule behind sign-up and the
 * profile form, and the bounds the admin bootstrap derives its name within.
 * It names the profile's address, so it stays lower-case letters, digits and
 * underscores.
 */
final class Username
{
    public const MIN = 3;

    public const MAX = 30;

    /**
     * @return list<string>
     */
    public static function rules(): array
    {
        return ['string', 'min:'.self::MIN, 'max:'.self::MAX, 'regex:/^[a-z0-9_]+$/'];
    }
}

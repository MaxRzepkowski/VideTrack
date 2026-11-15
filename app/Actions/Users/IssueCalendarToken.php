<?php

namespace App\Actions\Users;

use App\Models\User;
use Illuminate\Support\Str;

/**
 * A new token for the user's public iCal feed — opaque and unguessable, it
 * stands in for authentication on the subscription endpoint. Stored
 * encrypted, found by its hash; a token issued before it stops working.
 */
final readonly class IssueCalendarToken
{
    private const LENGTH = 48;

    public function execute(User $user): string
    {
        $token = Str::random(self::LENGTH);

        $user->forceFill([
            'calendar_token' => $token,
            'calendar_token_hash' => User::calendarTokenHash($token),
        ])->save();

        return $token;
    }
}

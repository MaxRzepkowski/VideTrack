<?php

namespace App\Actions\Users;

use App\Models\User;

/** Where Discord deliveries go; null turns them off. */
final readonly class SetDiscordWebhook
{
    public function execute(User $user, ?string $url): void
    {
        $user->discord_webhook_url = $url;
        $user->save();
    }
}

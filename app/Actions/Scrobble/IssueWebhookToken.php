<?php

namespace App\Actions\Scrobble;

use App\Models\WebhookEndpoint;
use Illuminate\Support\Str;

/**
 * A new token for a scrobble endpoint's address — the whole credential, like
 * the calendar feed token: stored encrypted, found by its hash. A new
 * endpoint is saved with its first; an existing one loses the address that
 * leaked.
 */
final readonly class IssueWebhookToken
{
    private const LENGTH = 48;

    public function execute(WebhookEndpoint $endpoint): void
    {
        $token = Str::random(self::LENGTH);

        $endpoint->forceFill(['token' => $token, 'token_hash' => WebhookEndpoint::tokenHash($token)])->save();
    }
}

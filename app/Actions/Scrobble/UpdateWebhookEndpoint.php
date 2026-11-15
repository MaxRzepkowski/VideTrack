<?php

namespace App\Actions\Scrobble;

use App\Models\WebhookEndpoint;

/**
 * An endpoint's own settings: its name, whether it listens, and whose plays
 * on a shared server it takes.
 */
final readonly class UpdateWebhookEndpoint
{
    public function execute(WebhookEndpoint $endpoint, ?string $label, bool $enabled, ?string $serverUsername): void
    {
        $endpoint->update([
            'label' => $label,
            'enabled' => $enabled,
            // Merged rather than replaced: later sources keep their own keys
            // in here, and this form knows about exactly one of them.
            'settings' => [
                ...($endpoint->settings ?? []),
                'server_username' => $serverUsername,
            ],
        ]);
    }
}

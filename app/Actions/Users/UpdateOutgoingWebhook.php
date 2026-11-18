<?php

namespace App\Actions\Users;

use App\Integrations\OutgoingWebhooks\Data\OutgoingWebhookSettings;
use App\Models\OutgoingWebhook;

/**
 * A webhook's name, address, secret, events or switch changed; what was not
 * sent stays as saved. A name left empty goes back to the host, and a new
 * address forgets how the old one failed.
 */
final readonly class UpdateOutgoingWebhook
{
    public function execute(OutgoingWebhook $webhook, OutgoingWebhookSettings $settings): void
    {
        if ($settings->url !== null && $settings->url !== $webhook->url) {
            $webhook->url = $settings->url;
            $webhook->last_failure = null;
        }

        if ($settings->label !== null) {
            $webhook->label = $webhook->nameFrom($settings->label);
        }

        $webhook->secret = $settings->secret ?? $webhook->secret;
        $webhook->enabled = $settings->enabled ?? $webhook->enabled;

        if ($settings->events !== null) {
            $webhook->events = collect($settings->events);
        }

        $webhook->save();
    }
}

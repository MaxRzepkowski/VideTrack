<?php

namespace App\Actions\Users;

use App\Http\Data\OutgoingWebhooksPage;
use App\Integrations\OutgoingWebhooks\Data\OutgoingWebhookData;
use App\Integrations\OutgoingWebhooks\Data\WebhookGroupOption;
use App\Integrations\OutgoingWebhooks\WebhookEventGroup;
use App\Integrations\OutgoingWebhooks\WebhookSignature;
use App\Models\OutgoingWebhook;
use App\Models\User;

/** The webhooks page: the user's webhooks, and a fresh secret for the next one. */
final readonly class BuildOutgoingWebhooksPage
{
    public function execute(User $viewer): OutgoingWebhooksPage
    {
        $webhooks = [];

        foreach ($viewer->outgoingWebhooks()->orderBy('id')->get() as $webhook) {
            $webhooks[] = new OutgoingWebhookData(
                id: $webhook->id,
                label: $webhook->label,
                host: $webhook->host(),
                events: array_values($webhook->events->all()),
                enabled: $webhook->enabled,
                lastFailure: $webhook->last_failure?->failureWording(),
                newSecret: WebhookSignature::newSecret(),
            );
        }

        return new OutgoingWebhooksPage(
            webhooks: $webhooks,
            groups: array_map(WebhookGroupOption::of(...), WebhookEventGroup::cases()),
            newSecret: WebhookSignature::newSecret(),
            labelMax: OutgoingWebhook::LABEL_MAX,
            urlMax: OutgoingWebhook::URL_MAX,
            limit: OutgoingWebhook::PER_USER,
        );
    }
}

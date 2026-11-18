<?php

namespace App\Actions\Users;

use App\Integrations\OutgoingWebhooks\WebhookClient;
use App\Integrations\OutgoingWebhooks\WebhookEventType;
use App\Integrations\OutgoingWebhooks\WebhookMessage;
use App\Models\OutgoingWebhook;
use App\Support\Delivery\DeliveryAnswer;
use App\Support\PublicUrl;
use Illuminate\Support\Str;

/**
 * "Send a test": one message to the webhook now, outside the queue, so the
 * user sees the answer. It counts as the webhook's latest delivery.
 */
final readonly class TestOutgoingWebhook
{
    public function __construct(
        private WebhookClient $client,
        private RecordOutgoingWebhookAnswer $record,
    ) {}

    public function execute(OutgoingWebhook $webhook): DeliveryAnswer
    {
        $answer = $this->client->send($webhook->url, $webhook->secret, new WebhookMessage(
            (string) Str::uuid7(),
            WebhookEventType::Test,
            now()->toIso8601String(),
            ['url' => PublicUrl::to(route('settings.webhooks.index', absolute: false))],
        ));

        $this->record->execute($webhook, $answer);

        return $answer;
    }
}

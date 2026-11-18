<?php

namespace App\Integrations\OutgoingWebhooks;

use App\Actions\Users\RecordOutgoingWebhookAnswer;
use App\Models\OutgoingWebhook;
use App\Support\Delivery\DeliveryRetry;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\SerializesModels;

/**
 * One message to one webhook, posted outside the write that caused it. The
 * message is made once, so a retry carries the same id and body. Carries
 * the webhook, never its address or secret: a credential has no place in the
 * queue. A receiver that says the address is closed for good switches the
 * webhook off; how the last try went stays on the webhook, for the page to tell.
 */
class DeliverOutgoingWebhook implements ShouldQueue
{
    use Dispatchable, Queueable, SerializesModels;

    /** A webhook removed before the queue got here has nobody to tell. */
    public bool $deleteWhenMissingModels = true;

    public int $tries = DeliveryRetry::TRIES;

    public function __construct(private readonly OutgoingWebhook $webhook, private readonly WebhookMessage $message) {}

    public function handle(WebhookClient $client, RecordOutgoingWebhookAnswer $record): void
    {
        // Switched off while queued.
        if (! $this->webhook->enabled) {
            return;
        }

        $answer = $client->send($this->webhook->url, $this->webhook->secret, $this->message);

        $retryAfter = DeliveryRetry::after($answer, $this->attempts());

        if ($retryAfter !== null) {
            $this->release($retryAfter);

            return;
        }

        $record->execute($this->webhook, $answer);
    }
}

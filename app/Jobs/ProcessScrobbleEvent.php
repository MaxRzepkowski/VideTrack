<?php

namespace App\Jobs;

use App\Actions\Scrobble\SettleDelivery;
use App\Enums\WebhookDeliveryStatus;
use App\Integrations\Scrobble\Data\ScrobbleEvent;
use App\Integrations\Scrobble\DeliveryNote;
use App\Models\WebhookDelivery;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * The slow half of ingest, on the queue: resolving a foreign id may cost a
 * provider round-trip and the sender is already gone. What a delivery does
 * is {@see SettleDelivery}'s; this is its retries and its last word.
 */
class ProcessScrobbleEvent implements ShouldQueue
{
    use Dispatchable, Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [30, 120];

    public function __construct(
        private readonly int $deliveryId,
        private readonly ScrobbleEvent $event,
    ) {}

    public function handle(SettleDelivery $settle): void
    {
        $delivery = WebhookDelivery::query()->with('webhookEndpoint.user')->find($this->deliveryId);

        // The endpoint (and with it the delivery) may have been deleted while
        // this sat in the queue.
        if ($delivery === null) {
            return;
        }

        $settle->execute($delivery, $this->event);
    }

    /**
     * The last attempt has failed for good; the log must not stay on
     * "received" forever.
     */
    public function failed(Throwable $exception): void
    {
        WebhookDelivery::query()->whereKey($this->deliveryId)->update([
            'status' => WebhookDeliveryStatus::Error->value,
            'message' => DeliveryNote::ProcessingFailed->value,
        ]);
    }
}

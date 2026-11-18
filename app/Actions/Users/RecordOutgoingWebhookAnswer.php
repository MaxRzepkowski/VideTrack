<?php

namespace App\Actions\Users;

use App\Models\OutgoingWebhook;
use App\Support\Delivery\DeliveryAnswer;

/**
 * How a webhook took its latest message, from the queue or from the test
 * button alike: a failure stays on it for the page to tell, one that arrived
 * clears it, and a receiver that says the address is closed for good
 * switches the webhook off.
 */
final readonly class RecordOutgoingWebhookAnswer
{
    public function execute(OutgoingWebhook $webhook, DeliveryAnswer $answer): void
    {
        $webhook->update([
            'last_failure' => $answer->failure(),
            ...($answer === DeliveryAnswer::Closed ? ['enabled' => false] : []),
        ]);
    }
}

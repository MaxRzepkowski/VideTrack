<?php

namespace App\Integrations\OutgoingWebhooks;

use App\Integrations\Connections\UserAddressDelivery;
use App\Models\OutgoingWebhook;
use App\Models\User;
use App\Support\Delivery\DeliveryAnswer;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;

/**
 * Posts a signed message to the address the user gave, which may well be in
 * their home network (Home Assistant, n8n), held to the address rules of the
 * instance ({@see UserAddressDelivery}). A redirect is not followed: the
 * signature was made for the address the user typed, and the receiver is
 * better told to update it.
 */
final readonly class WebhookClient
{
    private const TIMEOUT_SECONDS = 10;

    /** Mistyped, or not set up yet at the receiver. */
    private const GONE = [404];

    /** Standard Webhooks: the receiver asks to be sent nothing more. */
    private const CLOSED = [410];

    public function __construct(private UserAddressDelivery $delivery) {}

    public function send(OutgoingWebhook $webhook, User $owner, WebhookMessage $message): DeliveryAnswer
    {
        $body = $message->body();

        return $this->delivery->deliver($webhook->url, $owner, self::TIMEOUT_SECONDS, self::GONE, closed: self::CLOSED, send: function (PendingRequest $request) use ($webhook, $message, $body): Response {
            $timestamp = now()->getTimestamp();

            return $request
                ->withHeaders([
                    'webhook-id' => $message->id,
                    'webhook-timestamp' => (string) $timestamp,
                    'webhook-signature' => WebhookSignature::sign($webhook->secret, $message->id, $timestamp, $body),
                ])
                ->withBody($body, 'application/json')
                ->post($webhook->url);
        });
    }
}

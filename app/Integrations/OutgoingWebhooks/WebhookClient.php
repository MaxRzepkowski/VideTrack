<?php

namespace App\Integrations\OutgoingWebhooks;

use App\Support\Delivery\DeliveryAnswer;
use App\Support\Delivery\DeliveryHttpClient;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;

/**
 * Posts a signed message to the address the user gave, which may well be on
 * their own network (Home Assistant, n8n). A redirect is not followed: the
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

    public function __construct(private DeliveryHttpClient $http) {}

    public function send(string $url, string $secret, WebhookMessage $message): DeliveryAnswer
    {
        $body = $message->body();

        return $this->http->deliver(self::TIMEOUT_SECONDS, self::GONE, closed: self::CLOSED, send: function (PendingRequest $request) use ($url, $secret, $message, $body): Response {
            $timestamp = now()->getTimestamp();

            return $request->withoutRedirecting()
                ->withHeaders([
                    'webhook-id' => $message->id,
                    'webhook-timestamp' => (string) $timestamp,
                    'webhook-signature' => WebhookSignature::sign($secret, $message->id, $timestamp, $body),
                ])
                ->withBody($body, 'application/json')
                ->post($url);
        });
    }
}

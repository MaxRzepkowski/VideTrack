<?php

namespace App\Integrations\WebPush;

use App\Integrations\WebPush\Data\WebPushMessage;
use App\Models\PushSubscription;
use App\Support\Delivery\DeliveryAnswer;
use App\Support\Delivery\DeliveryHttpClient;
use Illuminate\Http\Client\PendingRequest;
use Minishlink\WebPush\Subscription;

/**
 * Hands a message to the push service of one browser (Google's, Mozilla's,
 * Apple's: the browser chose it), which passes it on whenever the device is
 * online.
 */
final readonly class WebPushClient
{
    private const TIMEOUT_SECONDS = 10;

    /** How long the push service keeps a message for a device that is off: later, the bell has it. */
    public const TTL_SECONDS = 24 * 60 * 60;

    /** How a push service says the browser no longer listens: unsubscribed, or its permission taken back. */
    private const GONE = [404, 410];

    /** Set again from the body when it is sent. */
    private const BODY_HEADERS = ['Content-Type', 'Content-Length'];

    public function __construct(private DeliveryHttpClient $http) {}

    public function send(PushSubscription $subscription, WebPushMessage $message, VapidCredentials $vapid): DeliveryAnswer
    {
        $request = (new PushRequests($vapid, self::TTL_SECONDS))->for(
            Subscription::create([
                'endpoint' => $subscription->endpoint,
                'publicKey' => $subscription->public_key,
                'authToken' => $subscription->auth_token,
                'contentEncoding' => $subscription->content_encoding->value,
            ]),
            json_encode($message, JSON_THROW_ON_ERROR),
        );

        $headers = [];

        foreach ($request->getHeaders() as $name => $values) {
            if (! in_array($name, self::BODY_HEADERS, true)) {
                $headers[$name] = implode(', ', $values);
            }
        }

        return $this->http->deliver(self::TIMEOUT_SECONDS, self::GONE, fn (PendingRequest $http) => $http
            ->withHeaders($headers)
            ->withBody((string) $request->getBody(), $request->getHeaderLine('Content-Type'))
            ->post((string) $request->getUri()));
    }
}

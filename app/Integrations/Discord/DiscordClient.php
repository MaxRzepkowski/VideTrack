<?php

namespace App\Integrations\Discord;

use App\Support\Delivery\DeliveryAnswer;
use App\Support\Delivery\DeliveryHttpClient;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;

/**
 * Posts to a Discord webhook — the instant ping, the daily digest and the
 * test button. The webhook keeps the name and avatar its owner gave it in
 * Discord. Best-effort by design: it answers how Discord took the message and never
 * throws, so a broken Discord fails no notification job.
 */
final readonly class DiscordClient
{
    private const TIMEOUT_SECONDS = 10;

    /** Deleted in Discord, or the address mistyped. */
    private const GONE = [401, 404];

    public function __construct(private DeliveryHttpClient $http) {}

    /**
     * @param  array<string, mixed>  $message  a Discord webhook payload (content, embeds)
     */
    public function send(string $url, array $message): DeliveryAnswer
    {
        return $this->http->deliver(self::TIMEOUT_SECONDS, self::GONE, fn (PendingRequest $request): Response => $request->asJson()->post($url, $message));
    }
}

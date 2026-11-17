<?php

namespace App\Integrations\Discord;

use App\Notifications\Channels\DeliveryAnswer;
use App\Support\HttpRetry;
use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Posts to a Discord webhook — the instant ping, the daily digest and the
 * test button. The webhook keeps the name and avatar its owner gave it in
 * Discord. Best-effort by design: it answers how Discord took the message and never
 * throws, so a broken Discord fails no notification job.
 */
final readonly class DiscordClient
{
    private const TIMEOUT_SECONDS = 10;

    private const RETRIES = 2;

    private const RETRY_DELAY_MS = 500;

    /**
     * @param  array<string, mixed>  $message  a Discord webhook payload (content, embeds)
     */
    public function send(string $url, array $message): DeliveryAnswer
    {
        return $this->answer(fn (): Response => $this->request()->post($url, $message));
    }

    /** Only a transient failure is tried again — never an answered 4xx. */
    private function request(): PendingRequest
    {
        return Http::timeout(self::TIMEOUT_SECONDS)
            ->retry(self::RETRIES, self::RETRY_DELAY_MS, HttpRetry::isTransient(...), throw: false)
            ->withUserAgent(config()->string('tracker.http.user_agent'))
            ->asJson();
    }

    /**
     * @param  Closure(): Response  $call
     */
    private function answer(Closure $call): DeliveryAnswer
    {
        try {
            $status = $call()->status();
        } catch (ConnectionException) {
            return DeliveryAnswer::Unavailable;
        }

        return match (true) {
            $status >= 200 && $status < 300 => DeliveryAnswer::Delivered,
            // Deleted in Discord, or the address mistyped.
            $status === 401 || $status === 404 => DeliveryAnswer::Gone,
            $status === 429 => DeliveryAnswer::Throttled,
            $status >= 500 => DeliveryAnswer::Unavailable,
            default => DeliveryAnswer::Refused,
        };
    }
}

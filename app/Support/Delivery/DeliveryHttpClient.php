<?php

namespace App\Support\Delivery;

use App\Support\HttpRetry;
use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * How every client that delivers a message posts and reads the answer: a
 * bounded wait, a quick second try only for a transient failure (never an
 * answered 4xx), the instance's User-Agent, and the status read as a
 * DeliveryAnswer. Never throws, so a dead receiver fails no job. Which
 * statuses say the address is gone is the receiver's own.
 */
final readonly class DeliveryHttpClient
{
    /** Tries of one message within one delivery; the queue spaces out any later ones. */
    public const TRIES = 2;

    private const RETRY_DELAY_MS = 500;

    /**
     * @param  list<int>  $gone  the statuses by which the service says the address leads nowhere
     * @param  Closure(PendingRequest): Response  $send  the service's own request, on the shared one
     * @param  list<int>  $closed  the statuses by which it says the address is closed for good
     */
    public function deliver(int $timeoutSeconds, array $gone, Closure $send, array $closed = []): DeliveryAnswer
    {
        $request = Http::timeout($timeoutSeconds)
            ->retry(self::TRIES, self::RETRY_DELAY_MS, HttpRetry::isTransient(...), throw: false)
            ->withUserAgent(config()->string('tracker.http.user_agent'));

        try {
            $status = $send($request)->status();
        } catch (ConnectionException) {
            return DeliveryAnswer::Unavailable;
        }

        return match (true) {
            $status >= 200 && $status < 300 => DeliveryAnswer::Delivered,
            in_array($status, $gone, true) => DeliveryAnswer::Gone,
            in_array($status, $closed, true) => DeliveryAnswer::Closed,
            $status === 429 => DeliveryAnswer::Throttled,
            $status >= 500 => DeliveryAnswer::Unavailable,
            default => DeliveryAnswer::Refused,
        };
    }
}

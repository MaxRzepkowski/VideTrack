<?php

namespace App\Support;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Throwable;

/**
 * Which failed outbound requests are worth a second try.
 *
 * Laravel's retry() repeats EVERY failed status unless told otherwise, so a
 * wrong key turns one 401 into a burst of them — which is how an IP ends up
 * banned for a day. A server that has answered with a
 * 4xx has said what it means; only a dropped connection or a 5xx is transient.
 */
final class HttpRetry
{
    public static function isTransient(Throwable $exception): bool
    {
        if ($exception instanceof ConnectionException) {
            return true;
        }

        return $exception instanceof RequestException && $exception->response->serverError();
    }

    /**
     * What an answered failure means: the service failing on its side, or
     * asking to be waited out, is an outage that may pass on its own; any
     * other 4xx is a refusal that asking again will not change.
     */
    public static function isOutage(Response $response): bool
    {
        return $response->serverError() || $response->status() === 429;
    }

    /**
     * Transient, or a 429: for a service whose rules ask to be waited out
     * when it says "too many", with the caller's own backoff.
     */
    public static function isTransientOrThrottled(Throwable $exception): bool
    {
        return self::isTransient($exception)
            || ($exception instanceof RequestException && $exception->response->status() === 429);
    }
}

<?php

namespace App\Integrations\Anime;

use App\Catalog\ProviderException;
use App\Support\ConnectionFailure;
use App\Support\HttpRetry;
use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;

/**
 * How the list trackers' clients tell an outage from a refusal: out of reach
 * or busy past our wait is "try later", a 5xx or a 429 as well; any other
 * answered failure is a refusal that asking again would only repeat.
 */
trait ReadsAnimeLists
{
    /**
     * @param  Closure(): void  $throttle
     * @param  Closure(): Response  $call
     *
     * @throws AnimeListUnavailable
     */
    private function send(string $service, Closure $throttle, Closure $call): Response
    {
        try {
            $throttle();
        } catch (ProviderException) {
            // Our own limiter waited as long as it may; the tracker was never asked.
            throw new AnimeListUnavailable('import.service_busy_try_later', ['service' => $service]);
        }

        try {
            return $call();
        } catch (ConnectionException $e) {
            throw new AnimeListUnavailable('import.service_unreachable', ['service' => $service, 'reason' => ConnectionFailure::reason($e)]);
        }
    }

    /**
     * @throws AnimeListUnavailable|AnimeListRefused
     */
    private function refuseFailed(string $service, Response $response): void
    {
        if (HttpRetry::isOutage($response)) {
            throw new AnimeListUnavailable('import.service_answered_try_later', ['service' => $service, 'status' => $response->status()]);
        }

        if ($response->failed()) {
            throw new AnimeListRefused('import.service_refused_the_request', ['service' => $service, 'status' => $response->status()]);
        }
    }
}

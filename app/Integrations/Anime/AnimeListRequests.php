<?php

namespace App\Integrations\Anime;

use App\Catalog\ProviderException;
use App\Integrations\ServiceHttpClient;
use App\Support\HttpRetry;
use App\Support\ServiceBusy;
use Closure;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;

/**
 * How the list trackers' clients ask and read. An import sends a tracker few
 * requests, so each gets a short wait and one quick second try. Out of reach
 * or busy past our own limiter is "try later", a 5xx as well; a 429 still
 * standing after the tries says the tracker asked to wait; any other
 * answered failure is a refusal that asking again would only repeat.
 */
final readonly class AnimeListRequests
{
    private const TIMEOUT_SECONDS = 15;

    private const TRIES = 2;

    private const RETRY_MS = 250;

    /** How long a 429 asks to wait when the tracker names no time. */
    private const DEFAULT_WAIT_SECONDS = 60;

    public function __construct(private ServiceHttpClient $http) {}

    /** A request to the tracker; null for one that is asked at a full address. */
    public function to(?string $baseUrl = null): PendingRequest
    {
        return $this->http->retrying($this->http->request($baseUrl, self::TIMEOUT_SECONDS), self::TRIES, self::RETRY_MS, HttpRetry::isTransient(...));
    }

    /**
     * @param  Closure(): void  $throttle
     * @param  Closure(): Response  $call
     *
     * @throws AnimeListUnavailable|ServiceBusy
     */
    public function send(string $service, Closure $throttle, Closure $call): Response
    {
        try {
            $throttle();
        } catch (ProviderException) {
            // Our own limiter waited as long as it may; the tracker was never asked.
            throw new AnimeListUnavailable('import.service_busy_try_later', ['service' => $service]);
        }

        return $this->http->unlessBusy($service, $this->http->reach($service, AnimeListUnavailable::class, $call), self::DEFAULT_WAIT_SECONDS);
    }

    /**
     * @throws AnimeListUnavailable|AnimeListRefused
     */
    public function refuseFailed(string $service, Response $response): void
    {
        if (HttpRetry::isOutage($response)) {
            throw new AnimeListUnavailable('import.service_answered_try_later', ['service' => $service, 'status' => $response->status()]);
        }

        if ($response->failed()) {
            throw new AnimeListRefused('import.service_refused_the_request', ['service' => $service, 'status' => $response->status()]);
        }
    }
}

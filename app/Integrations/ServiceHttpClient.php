<?php

namespace App\Integrations;

use App\Support\ConnectionFailure;
use App\Support\HttpRetry;
use App\Support\ServiceBusy;
use App\Support\ServiceUnavailable;
use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * How an integration's client asks a service: a bounded wait, JSON, the
 * instance's User-Agent, another try only after a failure worth one, and a
 * connection that stays dropped or a 429 that outlasts the tries told the
 * same way for every service. What any other answered status means is each
 * client's own.
 */
final readonly class ServiceHttpClient
{
    private const TIMEOUT_SECONDS = 30;

    /** A request to the service, asked once; $baseUrl is null for a client that asks full addresses. */
    public function request(?string $baseUrl = null, int $timeoutSeconds = self::TIMEOUT_SECONDS): PendingRequest
    {
        $request = Http::timeout($timeoutSeconds)
            ->acceptJson()
            ->withUserAgent(config()->string('tracker.http.user_agent'));

        return $baseUrl === null ? $request : $request->baseUrl($baseUrl);
    }

    /**
     * $request asked up to $tries times, each wait twice the one before from
     * $firstRetryMs, while $worthIt says the failure may pass
     * (HttpRetry::isTransient; isTransientOrThrottled for a service whose
     * rules ask a 429 to be waited out). An answered status never throws: the
     * client reads it.
     *
     * @param  Closure(?Throwable): bool  $worthIt
     */
    public function retrying(PendingRequest $request, int $tries, int $firstRetryMs, Closure $worthIt): PendingRequest
    {
        return $request->retry($tries, fn (int $attempt): int => $firstRetryMs * 2 ** ($attempt - 1), $worthIt, throw: false);
    }

    /**
     * The answer $send gets, or $unavailable for $service once the connection
     * stays dropped. The reason is told without the request, whose query may
     * hold the user's key.
     *
     * @template TUnavailable of ServiceUnavailable
     *
     * @param  Closure(): Response  $send
     * @param  class-string<TUnavailable>  $unavailable
     *
     * @throws TUnavailable
     */
    public function reach(string $service, string $unavailable, Closure $send): Response
    {
        try {
            return $send();
        } catch (ConnectionException $e) {
            throw new $unavailable('import.service_unreachable', ['service' => $service, 'reason' => ConnectionFailure::reason($e)]);
        }
    }

    /**
     * $response, unless it is a 429 still standing after the tries: then the
     * run waits out the service's Retry-After ($defaultWaitSeconds when it
     * names none) instead of spending a try of its own on it.
     *
     * @throws ServiceBusy
     */
    public function unlessBusy(string $service, Response $response, int $defaultWaitSeconds): Response
    {
        if ($response->status() === 429) {
            throw new ServiceBusy('import.service_asked_to_wait', HttpRetry::retryAfter($response, $defaultWaitSeconds), ['service' => $service]);
        }

        return $response;
    }
}

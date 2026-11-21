<?php

namespace App\Catalog\Providers\Concerns;

use App\Catalog\ProviderBusy;
use App\Catalog\ProviderCredential;
use App\Catalog\ProviderException;
use App\Catalog\ProviderRejected;
use App\Support\ConnectionFailure;
use App\Support\HttpRetry;
use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Outbound-request plumbing shared by the provider HTTP clients: one place for
 * the timeout/retry/JSON policy and for turning a dropped connection into a
 * uniform ProviderException. Each client still owns its own auth, request shape
 * and response-body handling — only the transport boilerplate lives here.
 */
trait SendsHttpRequests
{
    private const HTTP_TIMEOUT_SECONDS = 15;

    private const HTTP_RETRIES = 2;

    private const HTTP_RETRY_DELAY_MS = 250;

    /**
     * A pending request pre-configured with the shared timeout, retry and
     * Accept policy; pass a base URL to bind the client's host. Only
     * transient failures are retried — never an answered 4xx. A client on a
     * render path may ask for a shorter timeout and no retries.
     */
    protected function httpClient(
        ?string $baseUrl = null,
        int $timeoutSeconds = self::HTTP_TIMEOUT_SECONDS,
        int $retries = self::HTTP_RETRIES,
    ): PendingRequest {
        $client = Http::timeout($timeoutSeconds)
            ->retry($retries, self::HTTP_RETRY_DELAY_MS, HttpRetry::isTransient(...), throw: false)
            ->acceptJson();

        return $baseUrl === null ? $client : $client->baseUrl($baseUrl);
    }

    /** A credential the service cannot be asked without ({@see ProviderCredential}). */
    protected function credential(string $configKey, string $envName): string
    {
        return ProviderCredential::read($configKey, $envName);
    }

    /**
     * What a failed answer means: refused (a 4xx — the key, the grant, the
     * request), busy (a 429, waited out for as long as `Retry-After` says), or
     * not available this time (a 5xx). "Not found" (404) is each client's own
     * case, read before this.
     */
    protected function failure(Response $response, string $what): ProviderException|ProviderRejected
    {
        $message = "{$what} failed with status {$response->status()}.";

        if ($response->status() === 429) {
            return new ProviderBusy($message, HttpRetry::retryAfter($response, ProviderBusy::DEFAULT_RETRY_AFTER_SECONDS));
        }

        return HttpRetry::isOutage($response) || ! $response->clientError()
            ? new ProviderException($message)
            : new ProviderRejected($message);
    }

    /**
     * Run $call and translate a dropped connection (all retries exhausted) into
     * a "<label> is unreachable" ProviderException. The cURL exception is not
     * chained: its message carries the request URI with any key in the query,
     * and a chained cause ends up in failed_jobs and in report() output.
     *
     * @param  Closure(): Response  $call
     */
    protected function guardConnection(string $label, Closure $call): Response
    {
        try {
            return $call();
        } catch (ConnectionException $e) {
            throw new ProviderException("{$label} is unreachable: ".ConnectionFailure::reason($e));
        }
    }
}

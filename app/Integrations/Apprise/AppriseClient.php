<?php

namespace App\Integrations\Apprise;

use App\Notifications\Channels\DeliveryAnswer;
use App\Support\HttpRetry;
use App\Support\Payload;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Talks to the instance's apprise-api container: what services it delivers
 * to, and a message for the Apprise URL of a channel. Stateless: the URL
 * travels with every message and the container keeps nothing. Never throws —
 * it answers how the message was taken, so a dead channel fails no job.
 */
final readonly class AppriseClient
{
    private const TIMEOUT_SECONDS = 15;

    public const RETRIES = 2;

    private const RETRY_DELAY_MS = 500;

    /**
     * The services are asked for while a settings page renders: a container
     * that hangs must not hold the page, so this gets a short wait and no retry.
     */
    private const DETAILS_TIMEOUT_SECONDS = 3;

    /**
     * What the container says about every service it delivers to: their
     * names, URL templates and the tokens those take. Null when it cannot be
     * asked.
     *
     * @return list<array<array-key, mixed>>|null
     */
    public function details(): ?array
    {
        try {
            $response = Http::timeout(self::DETAILS_TIMEOUT_SECONDS)
                ->withUserAgent(config()->string('tracker.http.user_agent'))
                ->accept('application/json')
                ->get($this->url('/details/'), ['all' => 'yes']);
        } catch (ConnectionException) {
            return null;
        }

        return $response->successful() ? Payload::list(Payload::map($response->json())['schemas'] ?? null) : null;
    }

    public function send(string $urls, string $title, string $body): DeliveryAnswer
    {
        try {
            $status = Http::timeout(self::TIMEOUT_SECONDS)
                ->retry(self::RETRIES, self::RETRY_DELAY_MS, HttpRetry::isTransient(...), throw: false)
                ->withUserAgent(config()->string('tracker.http.user_agent'))
                ->asJson()
                ->post($this->url('/notify'), [
                    'urls' => $urls,
                    'title' => $title,
                    'body' => $body,
                    'type' => 'info',
                    'format' => 'text',
                ])
                ->status();
        } catch (ConnectionException) {
            return DeliveryAnswer::Unavailable;
        }

        return match (true) {
            $status >= 200 && $status < 300 => DeliveryAnswer::Delivered,
            // The URL is not one Apprise can use.
            $status === 400 => DeliveryAnswer::Gone,
            $status === 429 => DeliveryAnswer::Throttled,
            $status >= 500 => DeliveryAnswer::Unavailable,
            // 424: the service behind the URL did not take it; whatever went
            // through would arrive twice if asked again.
            default => DeliveryAnswer::Refused,
        };
    }

    private function url(string $path): string
    {
        return rtrim(config()->string('tracker.apprise.url'), '/').$path;
    }
}

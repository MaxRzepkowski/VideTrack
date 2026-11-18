<?php

namespace App\Integrations\Apprise;

use App\Support\Delivery\DeliveryAnswer;
use App\Support\Delivery\DeliveryHttpClient;
use App\Support\Payload;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
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

    /** The URL is not one Apprise can use. */
    private const GONE = [400];

    /**
     * The services are asked for while a settings page renders: a container
     * that hangs must not hold the page, so this gets a short wait and no retry.
     */
    private const DETAILS_TIMEOUT_SECONDS = 3;

    public function __construct(private DeliveryHttpClient $http) {}

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
        // A 424 (the service behind the URL did not take it) stays a refusal:
        // whatever went through would arrive twice if asked again.
        return $this->http->deliver(self::TIMEOUT_SECONDS, self::GONE, fn (PendingRequest $request): Response => $request->asJson()->post($this->url('/notify'), [
            'urls' => $urls,
            'title' => $title,
            'body' => $body,
            'type' => 'info',
            'format' => 'text',
        ]));
    }

    private function url(string $path): string
    {
        return rtrim(config()->string('tracker.apprise.url'), '/').$path;
    }
}

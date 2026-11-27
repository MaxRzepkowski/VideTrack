<?php

namespace App\Integrations\Anime;

use App\Catalog\Providers\Concerns\ThrottlesRequests;
use App\Support\Payload;
use App\Support\ServiceBusy;

/** Kitsu's JSON:API: user lookup and library pages. */
final class KitsuClient
{
    use ThrottlesRequests;

    /** Kitsu publishes no limit; two a second stays polite. */
    private const RATE_LIMIT_PER_SECOND = 2;

    public function __construct(private readonly AnimeListRequests $requests) {}

    /**
     * A path under the API, or an absolute `next` link — which carries the
     * whole query itself, so it travels with a null query.
     *
     * @param  array<string, string|int>|null  $query
     * @return array<array-key, mixed>
     *
     * @throws AnimeListUnavailable|AnimeListRefused|ServiceBusy
     */
    public function get(string $url, ?array $query): array
    {
        $response = $this->requests->send('Kitsu', fn () => $this->throttle('kitsu-api', self::RATE_LIMIT_PER_SECOND, 1), fn () => $this->requests->to(config()->string('tracker.imports.kitsu_url'))
            ->get($url, $query));

        $this->requests->refuseFailed('Kitsu', $response);

        return Payload::map($response->json());
    }
}

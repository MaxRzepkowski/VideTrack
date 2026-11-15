<?php

namespace App\Integrations\Anime;

use App\Catalog\Providers\Concerns\SendsHttpRequests;
use App\Catalog\Providers\Concerns\ThrottlesRequests;
use App\Support\Payload;

/** Kitsu's JSON:API: user lookup and library pages. */
final class KitsuClient
{
    use ReadsAnimeLists;
    use SendsHttpRequests;
    use ThrottlesRequests;

    /** Kitsu publishes no limit; two a second stays polite. */
    private const RATE_LIMIT_PER_SECOND = 2;

    /**
     * A path under the API, or an absolute `next` link — which carries the
     * whole query itself, so it travels with a null query.
     *
     * @param  array<string, string|int>|null  $query
     * @return array<array-key, mixed>
     *
     * @throws AnimeListUnavailable|AnimeListRefused
     */
    public function get(string $url, ?array $query): array
    {
        $response = $this->send('Kitsu', fn () => $this->throttle('kitsu-api', self::RATE_LIMIT_PER_SECOND, 1, 250_000), fn () => $this->httpClient(config()->string('tracker.imports.kitsu_url'))
            ->get($url, $query));

        $this->refuseFailed('Kitsu', $response);

        return Payload::map($response->json());
    }
}

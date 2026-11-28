<?php

namespace App\Integrations\Anime;

use App\Catalog\Providers\Concerns\ThrottlesRequests;
use App\Support\Payload;
use App\Support\ServiceBusy;
use Illuminate\Http\Client\Response;

/** Kitsu's JSON:API: user lookup, library pages and a title by its id. */
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
        $response = $this->send($url, $query);

        $this->requests->refuseFailed('Kitsu', $response);

        return Payload::map($response->json());
    }

    /**
     * One anime with its external mappings, to tell which MyAnimeList entry
     * it is; null for an id Kitsu holds no title under.
     *
     * @throws AnimeListUnavailable|AnimeListRefused|ServiceBusy
     */
    public function anime(int $id): ?KitsuDocument
    {
        $response = $this->send("anime/{$id}", ['include' => 'mappings', 'fields[anime]' => 'mappings', 'fields[mappings]' => 'externalSite,externalId']);

        if ($response->notFound()) {
            return null;
        }

        $this->requests->refuseFailed('Kitsu', $response);

        return KitsuDocument::of(Payload::map($response->json()));
    }

    /**
     * @param  array<string, string|int>|null  $query
     *
     * @throws AnimeListUnavailable|ServiceBusy
     */
    private function send(string $url, ?array $query): Response
    {
        return $this->requests->send('Kitsu', fn () => $this->throttle('kitsu-api', self::RATE_LIMIT_PER_SECOND, 1), fn () => $this->requests->to(config()->string('tracker.imports.kitsu_url'))
            ->get($url, $query));
    }
}

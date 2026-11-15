<?php

namespace App\Catalog\Providers\Tmdb;

use App\Catalog\Data\WatchAvailability;
use App\Catalog\Data\WatchProviderData;
use App\Support\Payload;

/**
 * Where to watch a title: its services per region, the regions it streams in,
 * every region TMDB knows.
 */
final readonly class TmdbWatchProviders
{
    public function __construct(
        private TmdbClient $client,
        private TmdbPayload $payload,
    ) {}

    public function watchProviders(string $type, string $ref, string $region): ?WatchAvailability
    {
        $path = $this->watchProvidersPath($type, $ref);

        if ($path === null) {
            return null;
        }

        // The response is language-independent and carries every region, so
        // one cache entry serves all users regardless of region preference.
        $data = $this->client->get($path, [], $this->payload->ttl('details'));

        $offers = Payload::map(data_get($data, "results.{$region}"));

        $availability = new WatchAvailability(
            region: $region,
            link: Payload::string($offers, 'link'),
            stream: $this->mapWatchProviders($offers, 'flatrate'),
            // TMDB keeps free services and ad-supported ones apart; to the
            // viewer both are "free to watch", and a service in both is one.
            free: $this->mapWatchProviders($offers, 'free', 'ads'),
            rent: $this->mapWatchProviders($offers, 'rent'),
            buy: $this->mapWatchProviders($offers, 'buy'),
        );

        return $availability->isEmpty() ? null : $availability;
    }

    /**
     * @return list<string>
     */
    public function watchProviderRegions(string $type, string $ref): array
    {
        $path = $this->watchProvidersPath($type, $ref);

        if ($path === null) {
            return [];
        }

        // Same cache entry watchProviders() reads — the response already
        // carries every region, so this costs no extra request.
        $data = $this->client->get($path, [], $this->payload->ttl('details'));

        $regions = [];

        foreach (Payload::map($data['results'] ?? null) as $region => $offers) {
            // `link` alone is not an offer: TMDB returns a JustWatch page for
            // regions that merely have an entry, with nothing to watch on it.
            $offers = Payload::map($offers);
            $kinds = array_filter(
                ['flatrate', 'free', 'ads', 'rent', 'buy'],
                fn (string $kind): bool => ! empty($offers[$kind]),
            );

            if (is_string($region) && $kinds !== []) {
                $regions[] = $region;
            }
        }

        sort($regions);

        return $regions;
    }

    /**
     * @return array<string, string>
     */
    public function watchRegions(string $locale): array
    {
        $data = $this->client->get('watch/providers/regions', [
            'language' => $this->payload->language($locale),
        ], $this->payload->ttl('listing'));

        $regions = [];

        foreach (Payload::list($data['results'] ?? null) as $region) {
            $code = Payload::string($region, 'iso_3166_1');
            $name = Payload::string($region, 'native_name') ?? Payload::string($region, 'english_name');

            if ($code !== null && $name !== null) {
                $regions[$code] = $name;
            }
        }

        asort($regions, SORT_NATURAL | SORT_FLAG_CASE);

        return $regions;
    }

    /**
     * @param  array<array-key, mixed>  $offers
     * @return list<WatchProviderData>
     */
    private function mapWatchProviders(array $offers, string ...$groups): array
    {
        $entries = array_merge(...array_map(fn (string $group): array => Payload::list($offers[$group] ?? null), $groups));

        usort($entries, fn (array $a, array $b): int => (Payload::int($a, 'display_priority') ?? 0) <=> (Payload::int($b, 'display_priority') ?? 0));

        $providers = [];

        foreach ($entries as $entry) {
            $name = Payload::string($entry, 'provider_name');

            if ($name === null) {
                continue;
            }

            // A service in two of the groups is one offer.
            $providers[$name] ??= new WatchProviderData(name: $name, logoPath: Payload::string($entry, 'logo_path'));
        }

        return array_values($providers);
    }

    private function watchProvidersPath(string $type, string $ref): ?string
    {
        if ($type === 'season') {
            [$showRef, $seasonNumber] = $this->payload->parseSeasonRef($ref);

            return "tv/{$showRef}/season/{$seasonNumber}/watch/providers";
        }

        return match ($type) {
            'movie' => "movie/{$ref}/watch/providers",
            'show' => "tv/{$ref}/watch/providers",
            default => null,
        };
    }
}

<?php

namespace App\Catalog\Providers\Tmdb;

use App\Catalog\Creators\CreatorKind;
use App\Catalog\Data\MediaSummary;
use App\Catalog\MatureContent;
use App\Support\Payload;

/**
 * Looking up people, studios and collections by name.
 */
final readonly class TmdbCreatorSearch
{
    public function __construct(
        private TmdbClient $client,
        private TmdbPayload $payload,
    ) {}

    /**
     * @return list<MediaSummary>
     */
    public function searchPeople(string $query, string $locale, MatureContent $mature): array
    {
        $data = $this->client->get('search/person', [
            'query' => $query,
            'language' => $this->payload->language($locale),
            'include_adult' => $mature->allowsAdult(),
        ], $this->payload->ttl('search'), 'results');

        $results = [];

        foreach (Payload::rows($data['results'] ?? null) as $result) {
            // No portrait → nothing to show on a card; skip like the title search.
            if (Payload::string($result, 'profile_path') === null) {
                continue;
            }

            $results[] = $this->payload->personSummary($result);
        }

        return $results;
    }

    /**
     * @return list<MediaSummary>
     */
    public function searchCollections(string $query, string $locale): array
    {
        $data = $this->client->get('search/collection', [
            'query' => $query,
            'language' => $this->payload->language($locale),
        ], $this->payload->ttl('search'), 'results');

        $results = [];

        foreach (Payload::list($data['results'] ?? null) as $result) {
            $ref = Payload::id($result['id'] ?? null);
            $name = Payload::string($result, 'name');

            if ($ref === null || $name === null) {
                continue;
            }

            $results[] = new MediaSummary(
                type: 'collection',
                ref: $ref,
                provider: $this->payload->code(),
                title: $name,
                posterPath: Payload::string($result, 'poster_path'),
                backdropPath: Payload::string($result, 'backdrop_path'),
            );
        }

        return $results;
    }

    /**
     * @return list<MediaSummary>
     */
    public function searchCompanies(string $query, string $locale): array
    {
        $data = $this->client->get('search/company', [
            'query' => $query,
        ], $this->payload->ttl('search'), 'results');

        $results = [];

        foreach (Payload::list($data['results'] ?? null) as $result) {
            $ref = Payload::id($result['id'] ?? null);
            $name = Payload::string($result, 'name');

            if ($ref === null || $name === null) {
                continue;
            }

            $results[] = new MediaSummary(
                type: CreatorKind::COMPANY_CARD,
                ref: $ref,
                provider: $this->payload->code(),
                title: $name,
                posterPath: Payload::string($result, 'logo_path'),
                creatorKind: CreatorKind::Company,
                makes: 'movie',
            );
        }

        return $results;
    }
}

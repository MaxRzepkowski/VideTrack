<?php

namespace App\Catalog\Providers\Igdb;

use App\Catalog\Data\CatalogLink;
use App\Catalog\Data\PlatformDetails;
use App\Catalog\ItemNotFoundException;
use App\Support\Payload;

/** A platform's page: the machine and its games, those coming and the best rated. */
final readonly class IgdbPlatforms
{
    /** Games a shelf shows: enough to read a platform by, not its whole catalogue. */
    private const SHELF_SIZE = 30;

    /** Ratings a game needs before "best rated" trusts its score. */
    private const MIN_RATINGS = 50;

    public function __construct(
        private IgdbClient $client,
        private IgdbPayload $payload,
    ) {}

    /**
     * The platforms a game's page links to.
     *
     * @param  array<array-key, mixed>  $data
     * @return list<CatalogLink>
     */
    public function of(array $data): array
    {
        $links = [];

        foreach (Payload::rows($data['platforms'] ?? null) as $platform) {
            $ref = Payload::id($platform['id'] ?? null);
            $name = Payload::string($platform, 'name');

            if ($ref !== null && $name !== null) {
                $links[] = new CatalogLink($ref, $name);
            }
        }

        return $links;
    }

    public function platform(string $ref): PlatformDetails
    {
        if (! ctype_digit($ref)) {
            throw new ItemNotFoundException("IGDB has no platform [{$ref}].");
        }

        $platform = Payload::map($this->client->query(
            'platforms',
            'fields name, abbreviation, generation, summary, platform_logo.image_id, platform_family.name, platform_type.name; '
            ."where id = {$ref};",
            $this->payload->ttl('details'),
            requireResults: true,
        )[0] ?? null);

        $games = fn (string $where, string $sort): array => array_map(
            fn (array $game) => $this->payload->summary($game),
            Payload::rows($this->client->query(
                'games',
                'fields '.IgdbPayload::SUMMARY_FIELDS."; where platforms = ({$ref}) & cover != null & version_parent = null & "
                .GameType::clause(GameType::trackable())." & {$where}; sort {$sort}; limit ".self::SHELF_SIZE.';',
                $this->payload->ttl('listing'),
            )),
        );

        return new PlatformDetails(
            ref: $ref,
            name: Payload::text($platform, 'name'),
            abbreviation: Payload::string($platform, 'abbreviation'),
            logoPath: $this->payload->imageUrl($platform, 'platform_logo', 'logo_med', 'png'),
            kind: Payload::string(Payload::map($platform['platform_type'] ?? null), 'name'),
            family: Payload::string(Payload::map($platform['platform_family'] ?? null), 'name'),
            generation: Payload::int($platform, 'generation'),
            summary: Payload::string($platform, 'summary'),
            upcoming: $games('first_release_date >= '.$this->payload->startOfTodayUtc(), 'first_release_date asc'),
            topRated: $games('total_rating_count >= '.self::MIN_RATINGS, 'total_rating desc'),
        );
    }
}

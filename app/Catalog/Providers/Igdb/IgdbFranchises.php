<?php

namespace App\Catalog\Providers\Igdb;

use App\Catalog\Data\CatalogLink;
use App\Catalog\Data\FranchiseDetails;
use App\Catalog\ItemNotFoundException;
use App\Support\Payload;

/**
 * A game's franchises (Resident Evil, The Witcher): everything under the
 * name, the series' games and the spin-offs beside them. Read as IGDB
 * answers; editions, DLC, bundles and ports stay on their games' pages.
 */
final readonly class IgdbFranchises
{
    /** The fields naming a game's franchises, for the details query. */
    public const FIELDS = 'franchise.name, franchises.name';

    /** Apicalypse's page ceiling; no franchise IGDB lists comes near it once editions and DLC are out. */
    private const LIMIT = 500;

    public function __construct(
        private IgdbClient $client,
        private IgdbPayload $payload,
    ) {}

    /**
     * The franchises a game's page links to, its main one first.
     *
     * @param  array<array-key, mixed>  $data
     * @return list<CatalogLink>
     */
    public function of(array $data): array
    {
        $links = [];

        foreach ([Payload::map($data['franchise'] ?? null), ...Payload::rows($data['franchises'] ?? null)] as $franchise) {
            $ref = Payload::id($franchise['id'] ?? null);
            $name = Payload::string($franchise, 'name');

            if ($ref !== null && $name !== null) {
                $links[$ref] ??= new CatalogLink($ref, $name);
            }
        }

        return array_values($links);
    }

    /** A franchise's games: those of a series' line apart from the rest, each in release order. */
    public function franchise(string $ref): FranchiseDetails
    {
        if (! ctype_digit($ref)) {
            throw new ItemNotFoundException("IGDB has no franchise [{$ref}].");
        }

        $franchise = Payload::map($this->client->query('franchises', "fields name, games; where id = {$ref};", $this->payload->ttl('details'), requireResults: true)[0] ?? null);
        $ids = $this->payload->numericIds(Payload::idList($franchise['games'] ?? null));

        $games = $ids === [] ? [] : Payload::rows($this->client->query(
            'games',
            'fields '.IgdbPayload::SUMMARY_FIELDS.'; where id = ('.implode(',', $ids).') & version_parent = null & cover != null & '
            .GameType::clause(GameType::lineParts()).'; limit '.self::LIMIT.';',
            $this->payload->ttl('details'),
        ));

        $inSeries = $this->seriesMembers($games);
        $series = [];
        $others = [];

        foreach ($games as $game) {
            $summary = $this->payload->summary($game);

            if (isset($inSeries[$summary->ref])) {
                $series[] = $summary;
            } else {
                $others[] = $summary;
            }
        }

        return new FranchiseDetails(
            ref: $ref,
            name: Payload::text($franchise, 'name'),
            series: IgdbPayload::inReleaseOrder($series),
            others: IgdbPayload::inReleaseOrder($others),
        );
    }

    /**
     * Which of the games belong to some series' line (a spin-off's membership
     * says so, and leaves it out).
     *
     * @param  list<array<array-key, mixed>>  $games
     * @return array<string, true>
     */
    private function seriesMembers(array $games): array
    {
        $ids = array_values(array_filter(array_map(fn (array $game): ?string => Payload::id($game['id'] ?? null), $games)));

        if ($ids === []) {
            return [];
        }

        $memberships = $this->client->query(
            'collection_memberships',
            'fields game; where game = ('.implode(',', $ids).') & type = '.IgdbPayload::MEMBER.'; limit '.self::LIMIT.';',
            $this->payload->ttl('details'),
        );

        $members = [];

        foreach (Payload::list($memberships) as $membership) {
            $game = Payload::id($membership['game'] ?? null);

            if ($game !== null) {
                $members[$game] = true;
            }
        }

        return $members;
    }
}

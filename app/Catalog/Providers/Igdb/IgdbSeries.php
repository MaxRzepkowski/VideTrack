<?php

namespace App\Catalog\Providers\Igdb;

use App\Catalog\Data\CollectionDetails;
use App\Catalog\Data\CollectionLink;
use App\Catalog\Data\CollectionRelations;
use App\Catalog\ItemNotFoundException;
use App\Support\Payload;

/**
 * A game's series: IGDB's collections (Resident Evil, The Witcher), the line of
 * games a "next part" belongs to. A franchise is wider (spin-offs, mobile
 * games) and is not this.
 */
final readonly class IgdbSeries
{
    /** The fields picking a game's series need, for the details query. */
    public const FIELDS = 'collections.name, collections.as_child_relations.parent_collection';

    /** IGDB's game_statuses name for a game that will not come out (queried from /v4/game_statuses). */
    private const CANCELLED = 'Cancelled';

    /** IGDB collection_relation_types (queried from /v4/collection_relation_types). */
    private const SUBSERIES = 1;

    private const SPIN_OFF_SERIES = 2;

    /** More than any series IGDB lists has parts once spin-offs and editions are out. */
    private const PARTS_LIMIT = 100;

    /** More members than any series IGDB lists has, spin-offs and editions included. */
    private const MEMBERSHIPS_LIMIT = 500;

    public function __construct(
        private IgdbClient $client,
        private IgdbPayload $payload,
    ) {}

    /**
     * The series a game's page links to. A game in a series and in a subseries
     * of it (Shadow of the Tomb Raider: "Tomb Raider" and "Tomb Raider:
     * Survivor") belongs to the subseries — the trilogy it continues.
     *
     * @param  array<array-key, mixed>  $data
     */
    public function of(array $data): ?CollectionLink
    {
        $series = Payload::rows($data['collections'] ?? null);
        $ids = array_map(fn (array $collection): ?string => Payload::id($collection['id'] ?? null), $series);

        $subseries = array_values(array_filter($series, function (array $collection) use ($ids): bool {
            foreach (Payload::list($collection['as_child_relations'] ?? null) as $relation) {
                if (in_array(Payload::id($relation['parent_collection'] ?? null), $ids, true)) {
                    return true;
                }
            }

            return false;
        }));

        $chosen = $subseries[0] ?? $series[0] ?? null;
        $ref = $chosen === null ? null : Payload::id($chosen['id'] ?? null);
        $name = $chosen === null ? null : Payload::string($chosen, 'name');

        return $ref === null || $name === null ? null : new CollectionLink($ref, $name);
    }

    /** A series and its parts in release order, the undated (announced) last. */
    public function collection(string $ref): CollectionDetails
    {
        if (! ctype_digit($ref)) {
            throw new ItemNotFoundException("IGDB has no series [{$ref}].");
        }

        $series = Payload::map($this->client->query(
            'collections',
            'fields name, as_child_relations.parent_collection.name, as_parent_relations.child_collection.name, as_parent_relations.type; '
            ."where id = {$ref};",
            $this->payload->ttl('details'),
            requireResults: true,
        )[0] ?? throw new ItemNotFoundException("IGDB returned no series for [{$ref}]."));

        $gameFields = IgdbPayload::summaryFieldsOf('game', 'version_parent, game_status.status');
        $memberships = $this->client->query(
            'collection_memberships',
            "fields {$gameFields}; where collection = {$ref} & type = ".IgdbPayload::MEMBER.'; limit '.self::MEMBERSHIPS_LIMIT.';',
            $this->payload->ttl('details'),
        );

        // A spin-off belongs to the series' world, not its line: the franchise's page lists it.
        $parts = [];

        foreach (Payload::list($memberships) as $membership) {
            $game = Payload::map($membership['game'] ?? null);

            if ($this->isPart($game)) {
                $parts[] = $this->payload->summary($game);
            }
        }

        $parts = array_slice(IgdbPayload::inReleaseOrder($parts), 0, self::PARTS_LIMIT);

        return new CollectionDetails(
            ref: $ref,
            name: Payload::text($series, 'name'),
            // IGDB draws no art for a series: its first part's cover stands in.
            posterPath: $parts[0]->posterPath ?? null,
            parts: $parts,
            relations: new CollectionRelations(
                parent: $this->link(Payload::list($series['as_child_relations'] ?? null)[0]['parent_collection'] ?? null),
                subseries: $this->children($series, self::SUBSERIES),
                spinOffSeries: $this->children($series, self::SPIN_OFF_SERIES),
            ),
        );
    }

    /**
     * The series under this one by the kind of tie (subseries, spin-off series).
     *
     * @param  array<array-key, mixed>  $series
     * @return list<CollectionLink>
     */
    private function children(array $series, int $type): array
    {
        $children = [];

        foreach (Payload::list($series['as_parent_relations'] ?? null) as $relation) {
            $link = Payload::int($relation, 'type') === $type ? $this->link($relation['child_collection'] ?? null) : null;

            if ($link !== null) {
                $children[] = $link;
            }
        }

        return $children;
    }

    private function link(mixed $collection): ?CollectionLink
    {
        $collection = Payload::map($collection);
        $ref = Payload::id($collection['id'] ?? null);
        $name = Payload::string($collection, 'name');

        return $ref === null || $name === null ? null : new CollectionLink($ref, $name);
    }

    /**
     * A part of the series (`GameType::seriesParts`), never cancelled; an
     * edition (`version_parent`) is its game again.
     *
     * @param  array<array-key, mixed>  $game
     */
    private function isPart(array $game): bool
    {
        return $game !== []
            && ($game['version_parent'] ?? null) === null
            // A cancelled game never joined the line; its franchise still lists it.
            && Payload::string(Payload::map($game['game_status'] ?? null), 'status') !== self::CANCELLED
            && ! empty($game['cover'])
            && in_array(Payload::intEnum($game, 'game_type', GameType::class), GameType::seriesParts(), true);
    }
}

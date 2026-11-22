<?php

namespace App\Catalog\Wikidata;

use App\Catalog\ExternalIdSource;
use App\Catalog\ExternalRefLookup;
use App\Catalog\ItemMetadata;
use App\Catalog\MediaKind;
use App\Catalog\MediaTypeRegistry;
use App\Models\MediaItem;
use App\Support\ExternalServiceException;

/**
 * Finds the Wikidata items of stored titles. A title whose provider names
 * its item (a film's TMDB page does) is not asked about; any other is found
 * by an id of ours Wikidata keeps (a manga's MyAnimeList number, a book's
 * Goodreads edition), all of a batch in one question.
 */
final readonly class TitleItems
{
    /** Items one answer may run to: a few per title at most, a stop for a runaway query. */
    private const MAX_ROWS = 2000;

    public function __construct(
        private WikidataClient $wikidata,
        private MediaTypeRegistry $types,
        private ExternalRefLookup $externalRefs,
    ) {}

    /**
     * The types whose titles can be found on Wikidata by an id of ours.
     *
     * @return list<string>
     */
    public function findableTypes(): array
    {
        $findable = [];

        foreach ($this->types->all() as $code => $type) {
            $kind = MediaKind::tryFrom($code);
            $catalogues = [$type->provider, ...array_map(fn (ExternalIdSource $source): string => $source->value, $this->externalRefs->sourcesFor($code))];

            if ($type->isRoot() && $kind !== null && array_filter($catalogues, fn (string $catalogue): bool => CatalogueId::finding($kind, $catalogue) !== null) !== []) {
                $findable[] = $code;
            }
        }

        return $findable;
    }

    /** Whether the title is one the relations task asks about: a root still at its provider, of a type it can find. */
    public function findable(MediaItem $title): bool
    {
        return $title->parent_id === null && $title->gone_at === null && in_array($title->type, $this->findableTypes(), true);
    }

    /**
     * Each title's items: usually one, more where Wikidata files the same
     * title twice (a game and its edition under one number).
     *
     * @param  iterable<MediaItem>  $titles
     * @return array<int, list<string>> by media item id; a title found nowhere has no entry
     *
     * @throws ExternalServiceException
     */
    public function of(iterable $titles): array
    {
        $items = [];
        $asked = [];

        foreach ($titles as $title) {
            $metadata = ItemMetadata::fromStored($title->metadata);

            if ($metadata->wikidataId !== null && WikidataClient::isItem($metadata->wikidataId)) {
                $items[$title->id] = [$metadata->wikidataId];

                continue;
            }

            $kind = MediaKind::tryFrom($title->type);

            if ($kind === null || ! $this->types->has($title->type)) {
                continue;
            }

            $ids = [$this->types->get($title->type)->provider => $title->provider_ref];

            if ($metadata->goodreadsId() !== null) {
                $ids[ExternalIdSource::Goodreads->value] = $metadata->goodreadsId();
            }

            foreach ($ids as $catalogue => $id) {
                $finding = CatalogueId::finding($kind, $catalogue);

                if ($finding !== null && ctype_digit($id)) {
                    $asked[$finding->value][$finding->wikidataValue($id)][] = $title->id;
                }
            }
        }

        foreach ($this->ask($asked) as [$id, $value, $item]) {
            foreach ($asked[$id][$value] ?? [] as $titleId) {
                $items[$titleId][] = $item;
            }
        }

        return array_map(fn (array $found): array => array_values(array_unique($found)), $items);
    }

    /**
     * @param  array<string, array<string, list<int>>>  $asked  by id case and value as Wikidata writes it
     * @return list<array{string, string, string}> the case, the value and the item it found
     *
     * @throws ExternalServiceException
     */
    private function ask(array $asked): array
    {
        if ($asked === []) {
            return [];
        }

        $branches = [];

        foreach ($asked as $case => $values) {
            $id = CatalogueId::from($case);
            $literals = implode(' ', array_map(fn (string $value): string => "\"{$value}\"", array_map(strval(...), array_keys($values))));
            $branches[] = "{ VALUES ?value { {$literals} } {$id->itemPattern('item', 'value')} BIND(\"{$case}\" AS ?id) }";
        }

        $union = implode("\nUNION ", $branches);
        $rows = self::MAX_ROWS;
        $sparql = <<<SPARQL
            SELECT ?id ?value ?item WHERE {
              {$union}
            }
            LIMIT {$rows}
            SPARQL;

        $found = [];

        foreach ($this->wikidata->select($sparql, RelatedWorks::TIMEOUT_SECONDS) as $row) {
            [$id, $value, $item] = [SparqlRow::value($row, 'id'), SparqlRow::value($row, 'value'), SparqlRow::entity($row, 'item')];

            if ($id !== null && $value !== null && $item !== null && WikidataClient::isItem($item)) {
                $found[] = [$id, $value, $item];
            }
        }

        return $found;
    }
}

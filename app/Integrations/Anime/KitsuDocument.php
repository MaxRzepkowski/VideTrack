<?php

namespace App\Integrations\Anime;

use App\Support\Payload;

/**
 * One answer of Kitsu's JSON:API: what it lists, and the records it
 * includes beside them, by type and id — where a title's external mappings
 * are, and so which MyAnimeList entry it is.
 */
final readonly class KitsuDocument
{
    /**
     * @param  array<array-key, mixed>  $page
     * @param  array<string, array<array-key, mixed>>  $records  the included records by "type:id"
     */
    private function __construct(public array $page, private array $records) {}

    /** @param  array<array-key, mixed>  $page */
    public static function of(array $page): self
    {
        $index = [];

        foreach (Payload::list($page['included'] ?? null) as $record) {
            $type = Payload::string($record, 'type');
            $id = Payload::id($record['id'] ?? null);

            if ($type !== null && $id !== null) {
                $index["{$type}:{$id}"] = $record;
            }
        }

        return new self($page, $index);
    }

    /**
     * An included record, null when the answer left it out.
     *
     * @return array<array-key, mixed>|null
     */
    public function included(string $type, string $id): ?array
    {
        return $this->records["{$type}:{$id}"] ?? null;
    }

    /**
     * The MyAnimeList entry a title's included mappings name, if one does.
     *
     * @param  array<array-key, mixed>  $media
     */
    public function malId(array $media, ListMedium $medium): ?int
    {
        foreach (Payload::list(data_get($media, 'relationships.mappings.data')) as $ref) {
            $mappingId = Payload::id($ref['id'] ?? null);
            $mapping = $mappingId === null ? [] : ($this->included('mappings', $mappingId) ?? []);
            $attributes = Payload::map($mapping['attributes'] ?? null);
            $externalId = Payload::string($attributes, 'externalId');

            // Some mappings carry junk (Farmagia's MAL id reads "anime").
            if (Payload::string($attributes, 'externalSite') === "myanimelist/{$medium->value}"
                && $externalId !== null
                && ctype_digit($externalId)) {
                return (int) $externalId;
            }
        }

        return null;
    }
}

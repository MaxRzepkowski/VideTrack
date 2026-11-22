<?php

namespace App\Catalog;

use App\Catalog\Data\ResolvedMedia;
use App\Models\ExternalIdMapping;
use Illuminate\Support\Facades\Cache;

/**
 * What is known already about foreign ids: the ref each one was found to be,
 * kept for good (a bulk import asks about the same ids thousands of times),
 * and the ids the provider knew nothing about, kept for a day so a genuinely
 * unknown one is not asked about on every delivery. One at a time for the
 * resolver, many at once for asking about a batch together.
 */
final readonly class ExternalIdMemory
{
    /** Remember a fruitless lookup for a day so we stop asking. */
    private const MISS_TTL_HOURS = 24;

    public function __construct(private MediaTypeRegistry $types) {}

    public function find(ExternalIdSource $source, string $id, MediaKind $mediaKind): ?ResolvedMedia
    {
        $mapped = ExternalIdMapping::query()
            ->where('source', $source)
            ->where('external_ref', $id)
            ->where('media_kind', $mediaKind)
            ->first();

        return $mapped === null ? null : new ResolvedMedia($mapped->type, $mapped->provider_ref);
    }

    /**
     * Of these ids of a source, the ones found already.
     *
     * @param  list<string>  $ids
     * @return array<string, true>
     */
    public function mapped(ExternalIdSource $source, array $ids, MediaKind $mediaKind): array
    {
        if ($ids === []) {
            return [];
        }

        $mapped = [];

        foreach (ExternalIdMapping::query()->where('source', $source)->where('media_kind', $mediaKind)->whereIn('external_ref', $ids)->get(['external_ref']) as $mapping) {
            $mapped[$mapping->external_ref] = true;
        }

        return $mapped;
    }

    public function isMiss(ExternalIdSource $source, string $id, MediaKind $mediaKind): bool
    {
        return Cache::get(self::missKey($source, $id, $mediaKind)) === true;
    }

    /**
     * Of these ids of a source, the ones the provider knew nothing about.
     *
     * @param  list<string>  $ids
     * @return array<string, true>
     */
    public function missed(ExternalIdSource $source, array $ids, MediaKind $mediaKind): array
    {
        if ($ids === []) {
            return [];
        }

        $keys = array_map(fn (string $id): string => self::missKey($source, $id, $mediaKind), $ids);
        $remembered = Cache::many($keys);
        $missed = [];

        foreach ($ids as $index => $id) {
            if (($remembered[$keys[$index]] ?? null) === true) {
                $missed[$id] = true;
            }
        }

        return $missed;
    }

    public function remember(ExternalIdSource $source, string $id, MediaKind $mediaKind, ResolvedMedia $resolved): void
    {
        ExternalIdMapping::query()->updateOrCreate(
            ['source' => $source, 'external_ref' => $id, 'media_kind' => $mediaKind],
            [
                'type' => $resolved->type,
                'provider' => $this->types->get($resolved->type)->provider,
                'provider_ref' => $resolved->ref,
            ],
        );
    }

    /**
     * @param  array<string, string>  $refs  foreign id => ref of the type of the kind's name
     */
    public function rememberAll(ExternalIdSource $source, MediaKind $mediaKind, array $refs): void
    {
        if ($refs === []) {
            return;
        }

        $provider = $this->types->get($mediaKind->value)->provider;
        $rows = [];

        foreach ($refs as $id => $ref) {
            $rows[] = ['source' => $source->value, 'external_ref' => $id, 'media_kind' => $mediaKind->value, 'type' => $mediaKind->value, 'provider' => $provider, 'provider_ref' => $ref];
        }

        ExternalIdMapping::query()->upsert($rows, ['source', 'external_ref', 'media_kind'], ['type', 'provider', 'provider_ref']);
    }

    public function rememberMiss(ExternalIdSource $source, string $id, MediaKind $mediaKind): void
    {
        $this->rememberMisses($source, [$id], $mediaKind);
    }

    /**
     * @param  list<string>  $ids
     */
    public function rememberMisses(ExternalIdSource $source, array $ids, MediaKind $mediaKind): void
    {
        if ($ids === []) {
            return;
        }

        Cache::putMany(array_fill_keys(array_map(fn (string $id): string => self::missKey($source, $id, $mediaKind), $ids), true), now()->addHours(self::MISS_TTL_HOURS));
    }

    private static function missKey(ExternalIdSource $source, string $id, MediaKind $mediaKind): string
    {
        return "extid-miss:{$source->value}:{$id}:{$mediaKind->value}";
    }
}

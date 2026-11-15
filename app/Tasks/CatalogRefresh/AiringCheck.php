<?php

namespace App\Tasks\CatalogRefresh;

use App\Catalog\Data\MediaSummary;
use App\Catalog\MediaTypeRegistry;
use App\Catalog\ProviderRegistry;
use App\Models\MediaItem;
use App\Support\ExternalServiceException;

/**
 * Whether a just refreshed tree agrees with what its title's own details say
 * of the latest and the next part to air. The tree's parts may come from a
 * batched answer the provider serves older than the title's details; this
 * names the trees where that shows.
 */
final readonly class AiringCheck
{
    public function __construct(
        private ProviderRegistry $providers,
        private MediaTypeRegistry $types,
    ) {}

    /** What disagrees, worded for the run's details; null when nothing does. */
    public function disagreement(MediaItem $root, string $locale): ?string
    {
        // The details were fetched by the refresh a moment ago, in the same
        // fresh scope: reading them again asks nothing. A measurement never
        // fails the refresh it watches.
        try {
            $details = $this->providers->forType($this->types->get($root->type))->details($root->type, $root->provider_ref, $locale);
        } catch (ExternalServiceException) {
            return null;
        }

        $airing = $details->airingEpisodes();

        if ($airing === []) {
            return null;
        }

        $stored = MediaItem::query()
            ->where('provider', $root->provider)
            ->whereIn('type', array_map(fn (MediaSummary $part): string => $part->type, $airing))
            ->whereIn('provider_ref', array_map(fn (MediaSummary $part): string => $part->ref, $airing))
            ->get()
            ->keyBy('provider_ref');

        $disagreements = [];

        foreach ($airing as $part) {
            $date = $stored->get($part->ref)?->release_date?->toDateString();

            if (! $stored->has($part->ref) || $date !== $part->releaseDate) {
                $disagreements[] = "{$part->ref} airs ".($part->releaseDate ?? 'undated').' by the title, '.($stored->has($part->ref) ? ($date ?? 'undated') : 'missing').' in the tree';
            }
        }

        return $disagreements === [] ? null : "{$root->type}:{$root->provider_ref} ({$root->title}): ".implode('; ', $disagreements).'.';
    }
}

<?php

namespace App\Actions\Media;

use App\Catalog\Contracts\SupportsHierarchy;
use App\Catalog\Data\MediaDetails;
use App\Catalog\Data\MediaSummary;
use App\Catalog\MediaTypeRegistry;
use App\Catalog\ProviderRegistry;
use App\Catalog\TitleStore;
use App\Models\MediaItem;
use App\Support\Numbers;

/**
 * "How long does the whole thing take" for a detail page — every episode's
 * runtime summed, never an average blown up by the episode count. Specials
 * (season 0) are left out of a show's total, like every other root-level
 * cascade: the number answers the same question marking the show watched does.
 * A specials season asked directly still totals its own episodes.
 *
 * A tracked item answers straight from the database: its whole tree is stored
 * and rolled up into `total_runtime` (see TitleStore) — no provider
 * call at all. An untracked show has to be walked at the provider, which is
 * why the caller defers it; SupportsHierarchy::leaves keeps that walk to as
 * few requests as the provider can manage (TMDB: one or two).
 */
final readonly class ResolveTotalRuntime
{
    public function __construct(
        private MediaTypeRegistry $types,
        private ProviderRegistry $providers,
    ) {}

    /** @return int|null  minutes, NULL when nothing in the item carries a runtime */
    public function execute(string $type, string $ref, MediaDetails $details, string $locale): ?int
    {
        $stored = $this->stored($type, $ref);

        if ($stored !== null) {
            return $stored;
        }

        $definition = $this->types->get($type);
        $childType = $definition->childType;

        // Flat types (movie, episode, game): their own runtime is the total.
        if ($childType === null) {
            return $details->runtime;
        }

        // A season carries its episodes with the page — sum them directly.
        if ($this->types->get($childType)->childType === null) {
            return $this->sum($this->childRuntimes($details->children));
        }

        // A show carries seasons only, so its episodes have to be fetched, in
        // as few requests as the provider can manage (TMDB: ceil(seasons/20)).
        $provider = $this->providers->forType($definition);

        if (! $provider instanceof SupportsHierarchy) {
            return null;
        }

        // leaves() hands back every episode of the show, specials among them;
        // each carries the season it came from, so they drop out here rather
        // than costing a narrower request.
        $leaves = $provider->leaves($type, $ref, $locale);
        $specials = $definition->specialsChildNumber;

        if ($specials !== null) {
            $leaves = array_values(array_filter(
                $leaves,
                fn (MediaSummary $leaf): bool => $leaf->seasonNumber !== $specials,
            ));
        }

        return $this->sum($this->childRuntimes($leaves));
    }

    private function stored(string $type, string $ref): ?int
    {
        return Numbers::int(MediaItem::query()
            ->where([
                'provider' => $this->types->get($type)->provider,
                'type' => $type,
                'provider_ref' => $ref,
            ])
            ->value('total_runtime'));
    }

    /**
     * @param  list<MediaSummary>  $children
     * @return list<int>
     */
    private function childRuntimes(array $children): array
    {
        $runtimes = [];

        foreach ($children as $child) {
            if ($child->runtime !== null && $child->runtime > 0) {
                $runtimes[] = $child->runtime;
            }
        }

        return $runtimes;
    }

    /**
     * NULL rather than 0 when no episode carries a runtime — a type without
     * runtimes (books, comics) must stay empty, not read as instant to finish.
     *
     * @param  list<int>  $runtimes
     */
    private function sum(array $runtimes): ?int
    {
        return $runtimes === [] ? null : array_sum($runtimes);
    }
}

<?php

namespace App\Integrations\Import;

use App\Catalog\Data\MediaSummary;
use App\Catalog\Data\ResolvedMedia;
use App\Catalog\HierarchyPath;
use App\Catalog\ItemNotFoundException;
use App\Catalog\Matching;
use App\Catalog\MediaKind;
use App\Catalog\MediaTypeRegistry;
use App\Catalog\ProviderRegistry;
use App\Integrations\Import\Data\TitleQuery;

/**
 * Places a title by name when no id does: one search at the provider serving
 * the type, the first result of that type that agrees with every hint.
 *
 * Deliberately a part of the import framework, not of ExternalIdResolver —
 * a match by name can be wrong, so only a source that accepts the risk asks
 * for it, and RunImport tells the user how many titles were placed this way.
 */
final class TitleMatcher
{
    /** @var array<string, ResolvedMedia|null> per run: one search per distinct query */
    private array $memo = [];

    public function __construct(
        private readonly MediaTypeRegistry $types,
        private readonly ProviderRegistry $providers,
        private readonly HierarchyPath $hierarchy,
    ) {}

    /**
     * An episode is never searched for itself: its query names the show, and
     * its season and number lead down from there.
     */
    public function match(MediaKind $kind, TitleQuery $query, string $locale, ?int $season = null, ?int $episode = null): ?ResolvedMedia
    {
        if ($kind === MediaKind::Episode) {
            $show = $season === null || $episode === null ? null : $this->match(MediaKind::Show, $query, $locale);

            return $show === null ? null : $this->hierarchy->episode($show, $season, $episode);
        }

        $type = $kind->value;
        $title = trim($query->title);

        if ($title === '' || ! $this->types->has($type)) {
            return null;
        }

        $key = implode('|', [$type, mb_strtolower($title), $query->year, mb_strtolower((string) $query->creator), (int) $query->exactTitle]);

        if (array_key_exists($key, $this->memo)) {
            return $this->memo[$key];
        }

        // A search that could not be asked goes up unremembered: the next
        // entry with the same title asks again, and the runner reports this
        // one as unavailable, not as unmatched.
        try {
            $results = $this->providers->forType($this->types->get($type))->search($title, $locale);
        } catch (ItemNotFoundException) {
            $results = [];
        }

        foreach ($results as $summary) {
            if ($summary->type === $type && $this->agrees($summary, $query)) {
                return $this->memo[$key] = new ResolvedMedia($type, $summary->ref);
            }
        }

        return $this->memo[$key] = null;
    }

    private function agrees(MediaSummary $summary, TitleQuery $query): bool
    {
        if ($query->exactTitle && ! Matching::namesTitle($summary, $query->title)) {
            return false;
        }

        // A year off by one is the same film released across a new year's
        // eve, or a festival premiere before the theatrical one.
        if ($query->year !== null && ($summary->year === null || abs($summary->year - $query->year) > 1)) {
            return false;
        }

        if ($query->creator === null || trim($query->creator) === '') {
            return true;
        }

        return Matching::sharesAuthor([$query->creator], $summary);
    }
}

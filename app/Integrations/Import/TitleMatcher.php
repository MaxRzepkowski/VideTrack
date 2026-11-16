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
use Closure;

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

    /** @var array<string, list<MediaSummary>> per run: a show's episodes, asked for once */
    private array $episodes = [];

    /** @var array<string, list<MediaSummary>> per run: what a search by one name answered */
    private array $searches = [];

    public function __construct(
        private readonly MediaTypeRegistry $types,
        private readonly ProviderRegistry $providers,
        private readonly HierarchyPath $hierarchy,
    ) {}

    /**
     * An episode is never searched for itself: its query names the show, and
     * its season and number lead down from there — or, for a source that
     * names an episode only by its title, the season and that title.
     */
    public function match(MediaKind $kind, TitleQuery $query, string $locale, ?int $season = null, ?int $episode = null, ?string $episodeTitle = null): ?ResolvedMedia
    {
        if ($kind === MediaKind::Episode) {
            $named = $episodeTitle !== null && trim($episodeTitle) !== '';
            $show = $season === null || ($episode === null && ! $named) ? null : $this->match(MediaKind::Show, $query, $locale);

            if ($show === null) {
                return null;
            }

            return $episode !== null ? $this->hierarchy->episode($show, $season, $episode) : $this->episodeNamed($show, $season, (string) $episodeTitle, $locale);
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
        $results = $this->search($type, $title, $locale);
        $match = $this->first($results, $type, $query, fn (MediaSummary $summary): bool => ! $query->exactTitle || Matching::namesTitle($summary, $query->title));

        // A launcher's or a store's name for a game carries its edition and
        // trademark signs: the same results are read again without them,
        // then the bare name is searched for itself.
        if ($match === null && $kind === MediaKind::Game && $query->exactTitle) {
            $base = Matching::gameBase($title);
            $more = $base === $title ? [] : $this->search($type, $base, $locale);
            $match = $this->first([...$results, ...$more], $type, $query, fn (MediaSummary $summary): bool => Matching::namesGame($summary, $title));
        }

        return $this->memo[$key] = $match;
    }

    /**
     * The show's episode of that title: in the season named, else the one
     * episode of the show going by it — a service that splits a season in
     * parts numbers them as seasons the provider does not have. Two
     * episodes of one title elsewhere ("Pilot") name none.
     */
    private function episodeNamed(ResolvedMedia $show, int $season, string $title, string $locale): ?ResolvedMedia
    {
        $episodes = $this->episodes["{$show->type}:{$show->ref}"] ??= $this->hierarchy->episodes($show, $locale);
        $named = array_values(array_filter($episodes, fn (MediaSummary $leaf): bool => Matching::sameTitle($leaf->title, $title)));
        $inSeason = array_values(array_filter($named, fn (MediaSummary $leaf): bool => $leaf->seasonNumber === $season));
        $leaf = $inSeason[0] ?? (count($named) === 1 ? $named[0] : null);

        return $leaf === null ? null : new ResolvedMedia($leaf->type, $leaf->ref);
    }

    /**
     * @return list<MediaSummary>
     */
    private function search(string $type, string $title, string $locale): array
    {
        $key = implode('|', [$type, mb_strtolower($title)]);

        if (array_key_exists($key, $this->searches)) {
            return $this->searches[$key];
        }

        try {
            return $this->searches[$key] = $this->providers->forType($this->types->get($type))->search($title, $locale);
        } catch (ItemNotFoundException) {
            return $this->searches[$key] = [];
        }
    }

    /**
     * The first result of the type that names the title as asked and
     * agrees with every other hint.
     *
     * @param  list<MediaSummary>  $results
     * @param  Closure(MediaSummary): bool  $names
     */
    private function first(array $results, string $type, TitleQuery $query, Closure $names): ?ResolvedMedia
    {
        foreach ($results as $summary) {
            if ($summary->type === $type && $names($summary) && $this->agrees($summary, $query)) {
                return new ResolvedMedia($type, $summary->ref);
            }
        }

        return null;
    }

    private function agrees(MediaSummary $summary, TitleQuery $query): bool
    {
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

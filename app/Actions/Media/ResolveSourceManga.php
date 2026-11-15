<?php

namespace App\Actions\Media;

use App\Catalog\Data\MediaDetails;
use App\Catalog\Data\MediaSummary;
use App\Catalog\ExternalIdResolver;
use App\Catalog\ItemNotFoundException;
use App\Catalog\MediaKind;
use App\Catalog\MediaTypeRegistry;
use App\Catalog\ProviderException;
use App\Catalog\ProviderRegistry;
use App\Catalog\ProviderRejected;
use App\Catalog\Wikidata\SourceWorks;

/**
 * The manga an anime is based on, rendered through our own manga provider.
 * The cleanest bridge of all: Wikidata keeps the MyAnimeList id of the work
 * the anime is based on, and the id resolver turns it into our manga ref —
 * an identifier lookup, no title search or fuzzy matching.
 *
 * Manga, a comic subclass in Wikidata, is skipped by the book bridge, so the
 * two never collide.
 *
 * Experimental enrichment: a missing QID, a disabled manga type, or any lookup
 * failure just drops the section — never breaks the page.
 */
final readonly class ResolveSourceManga
{
    public function __construct(
        private SourceWorks $sources,
        private ExternalIdResolver $resolver,
        private MediaTypeRegistry $types,
        private ProviderRegistry $providers,
    ) {}

    /**
     * @return list<MediaSummary>|null
     */
    public function execute(MediaDetails $anime, string $locale): ?array
    {
        $qid = $anime->wikidataId;

        if ($qid === null || ! SourceWorks::isItem($qid) || ! $this->types->has(MediaKind::Manga->value)) {
            return null;
        }

        $matches = [];

        foreach ($this->sources->mangaBasedOn($qid) as $externalIds) {
            try {
                $resolved = $this->resolver->resolve($externalIds, MediaKind::Manga, $locale);

                if ($resolved !== null) {
                    $matches[] = $this->providers->forType($this->types->get($resolved->type))
                        ->details($resolved->type, $resolved->ref, $locale)
                        ->summary;
                }
            } catch (ItemNotFoundException|ProviderException|ProviderRejected) {
                continue;
            }
        }

        return $matches === [] ? null : $matches;
    }
}

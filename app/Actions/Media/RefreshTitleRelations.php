<?php

namespace App\Actions\Media;

use App\Catalog\Relations\RelationStore;
use App\Catalog\Relations\WorkFinder;
use App\Catalog\Wikidata\RelatedWorks;
use App\Models\MediaItem;
use App\Support\ExternalServiceException;
use Illuminate\Database\Eloquent\Collection;

/**
 * Asks the outside source what a batch of stored titles is tied to and which
 * franchises they belong to, finds each tied work in our catalogue and keeps
 * the ties as the source gave them: what of them a page shows is TieRules'
 * to say, so the rules can change without asking again. A work is left out
 * only when our catalogue does not have it or its provider files it as adult
 * material. The franchises found are listed by ListFranchiseTitles.
 */
final readonly class RefreshTitleRelations
{
    public function __construct(
        private RelatedWorks $relatedWorks,
        private RelationStore $store,
        private WorkFinder $works,
    ) {}

    /**
     * @param  Collection<int, MediaItem>  $titles  roots the source knows
     * @return int ties kept
     *
     * @throws ExternalServiceException the source or a provider did not answer; titles already written stay written
     */
    public function execute(Collection $titles, string $locale): int
    {
        $bySourceId = [];

        foreach ($titles as $title) {
            $sourceId = $this->store->sourceIdOf($title);

            if ($sourceId !== null) {
                $bySourceId[$sourceId] = $title;
            }
        }

        $answer = $this->relatedWorks->of(array_keys($bySourceId));

        // Every tied work of the batch is found in one pass, not one by one.
        $works = [];

        foreach ($answer->ties as $sourceId => $ofTitle) {
            foreach ($ofTitle as $index => $work) {
                $works["{$sourceId}:{$index}"] = $work;
            }
        }

        $found = $this->works->findAll($works, $locale);
        $kept = 0;

        foreach ($bySourceId as $sourceId => $title) {
            $ties = [];

            foreach ($answer->ties[$sourceId] ?? [] as $index => $work) {
                $card = $found["{$sourceId}:{$index}"] ?? null;

                // The source lists a title among its own ties now and then.
                if ($card !== null && ! ($card['card']->type === $title->type && $card['card']->ref === $title->provider_ref)) {
                    $ties[] = [$work->relation, $card['card']];
                }
            }

            $this->store->replace($title, $ties);
            $this->store->linkFranchises($title, $answer->franchises[$sourceId] ?? []);
            $kept += count($ties);
        }

        return $kept;
    }
}

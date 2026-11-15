<?php

namespace App\Actions\Media;

use App\Catalog\Relations\RelationStore;
use App\Catalog\Relations\WorkFinder;
use App\Catalog\Wikidata\RelatedWorks;
use App\Support\ExternalServiceException;

/**
 * Asks the outside source for every film and show of a batch of franchises
 * and keeps each as a card. A franchise is listed once, however many stored
 * titles belong to it.
 */
final readonly class ListFranchiseTitles
{
    public function __construct(
        private RelatedWorks $relatedWorks,
        private RelationStore $store,
        private WorkFinder $works,
    ) {}

    /**
     * @param  list<string>  $franchises  the source's ids
     * @return int titles kept
     *
     * @throws ExternalServiceException the source or a provider did not answer; franchises already written stay written
     */
    public function execute(array $franchises, string $locale): int
    {
        $members = $this->relatedWorks->membersOf($franchises);

        // Every title of the batch is found in one pass, not one by one.
        $works = [];

        foreach ($members as $franchise => $ofFranchise) {
            foreach ($ofFranchise as $index => $work) {
                $works["{$franchise}:{$index}"] = $work;
            }
        }

        $found = $this->works->findAll($works, $locale);
        $kept = 0;

        foreach ($franchises as $franchise) {
            $cards = [];

            foreach (array_keys($members[$franchise] ?? []) as $index) {
                $card = $found["{$franchise}:{$index}"] ?? null;

                if ($card !== null) {
                    $cards[] = [$card['card'], $card['collection']?->ref, $card['votes']];
                }
            }

            $this->store->fillFranchise($franchise, $cards);
            $kept += count($cards);
        }

        return $kept;
    }
}

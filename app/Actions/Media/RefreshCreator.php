<?php

namespace App\Actions\Media;

use App\Catalog\Creators\CompanyRecords;
use App\Catalog\Creators\CreatorPages;
use App\Catalog\Creators\CreatorRef;
use App\Catalog\Creators\Creators;
use App\Catalog\Creators\PersonRecords;
use App\Catalog\MatureChoices;
use App\Models\Company;
use App\Models\Person;

/**
 * Re-reads a person, studio or network at its provider. The page itself is
 * fetched fresh on every view; what goes stale is the stored row every card
 * elsewhere renders (a portrait the provider moved), so a row is rewritten
 * when there is one — a creator nobody attached to gets no row just for being
 * looked at.
 */
final readonly class RefreshCreator
{
    public function __construct(
        private Creators $creators,
        private CreatorPages $pages,
        private PersonRecords $people,
        private CompanyRecords $companies,
    ) {}

    public function execute(CreatorRef $creator, string $locale): void
    {
        $row = $this->creators->find($creator);

        match (true) {
            $row instanceof Person => $this->people->refresh($row, $locale),
            $row instanceof Company => $this->companies->refresh($row, $locale),
            default => $this->pages->page($creator, $locale, MatureChoices::shared()),
        };
    }
}

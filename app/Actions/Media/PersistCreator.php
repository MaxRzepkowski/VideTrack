<?php

namespace App\Actions\Media;

use App\Catalog\Creators\CompanyRecords;
use App\Catalog\Creators\CreatorKind;
use App\Catalog\Creators\CreatorRef;
use App\Catalog\Creators\PersonRecords;
use App\Models\Company;
use App\Models\MediaList;
use App\Models\Person;

/**
 * Gives a creator its row on first interaction — a bell, a list — asking its
 * provider only when the row does not exist yet.
 */
final readonly class PersistCreator
{
    public function __construct(
        private PersonRecords $people,
        private CompanyRecords $companies,
        private MaterializeCollection $collections,
    ) {}

    public function execute(CreatorRef $creator, string $locale): Person|Company|MediaList
    {
        return match ($creator->kind) {
            CreatorKind::Person => $this->people->ensure($creator->provider, $creator->ref, $locale),
            CreatorKind::Company, CreatorKind::Network => $this->companies->ensure($creator->provider, $creator->ref, $creator->kind, $locale),
            CreatorKind::Collection => $this->collections->execute($creator->provider, $creator->ref, $locale),
        };
    }
}

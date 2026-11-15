<?php

namespace App\Catalog\Creators;

use App\Catalog\Data\CompanyDetails;
use App\Catalog\Data\PersonCredit;
use App\Catalog\Data\PersonDetails;
use App\Catalog\MediaItemSummaries;
use App\Models\Company;
use App\Models\Credit;
use App\Models\Person;

/**
 * A creator page's details rebuilt from the stored row when the provider is
 * unreachable — the person and company counterpart of DetailsFromLibrary.
 */
final readonly class CreatorsFromLibrary
{
    public function __construct(
        private MediaItemSummaries $cards,
        private PersonRecords $people,
        private CompanyRecords $companies,
    ) {}

    public function person(Person $person): PersonDetails
    {
        // Whatever of their work is in the library — the titles this user
        // actually tracks, which is the part they came for.
        return $this->people->details($person, $this->filmography($person));
    }

    public function company(Company $company): CompanyDetails
    {
        return $this->companies->details($company);
    }

    /**
     * @return list<PersonCredit>
     */
    private function filmography(Person $person): array
    {
        $rows = Credit::query()
            ->where('person_id', $person->id)
            ->with('mediaItem.parent.parent')
            ->get();

        $credits = [];

        foreach ($rows as $row) {
            $item = $row->mediaItem;

            if ($item === null) {
                continue;
            }

            $credits[] = new PersonCredit(
                media: $this->cards->inContext($item),
                department: $row->department,
                role: $row->role,
            );
        }

        return $credits;
    }
}

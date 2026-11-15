<?php

namespace App\Catalog\Data;

/**
 * A creator page's details and where they came from. Served from the library
 * when the provider failed; a page in that mode says so (an incomplete page
 * that does not say why is worse than an error).
 */
final readonly class CreatorPage
{
    public function __construct(
        public PersonDetails|CompanyDetails $details,
        public bool $fromLibrary = false,
    ) {}

    /**
     * The titles on the page, which carry the user's state.
     *
     * @return list<MediaSummary>
     */
    public function titles(): array
    {
        return array_map(fn (PersonCredit|CompanyCredit $credit): MediaSummary => $credit->media, $this->details->credits);
    }
}

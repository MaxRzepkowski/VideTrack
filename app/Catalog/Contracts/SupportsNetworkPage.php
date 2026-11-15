<?php

namespace App\Catalog\Contracts;

use App\Catalog\Data\CompanyDetails;
use App\Catalog\Data\CreditPage;

/**
 * Capability: the provider serves a TV network page — the network analogue of
 * SupportsCompanyPage. A network (HBO, Netflix) is a followable creator entity
 * like a studio: a logo and a showography of what airs on it, so it reuses
 * {@see CompanyDetails}. Kept distinct from SupportsCompanyPage because a
 * network id and a production-company id share the provider's number space.
 */
interface SupportsNetworkPage
{
    public function network(string $ref, string $locale): CompanyDetails;

    /**
     * One chunk of the network's showography, 1-based — the network analogue
     * of {@see SupportsCompanyPage::companyCredits()}.
     */
    public function networkCredits(string $ref, string $locale, int $page): CreditPage;
}

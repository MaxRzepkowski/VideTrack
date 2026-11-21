<?php

namespace App\Catalog\Contracts;

use App\Catalog\Data\CompanyDetails;
use App\Catalog\Data\CreditPage;
use App\Catalog\MatureContent;

/**
 * Capability: the provider serves a company page (a studio / publisher profile
 * with a ludography). The company analogue of SupportsPersonPage.
 */
interface SupportsCompanyPage
{
    public function company(string $ref, string $locale, MatureContent $mature): CompanyDetails;

    /**
     * One chunk of the company's catalogue, 1-based. Page 1 is what
     * {@see self::company()} already carries, so the page only asks for 2 and
     * up — a studio's catalogue runs to thousands of titles and no provider
     * hands them over in one answer.
     */
    public function companyCredits(string $ref, string $locale, MatureContent $mature, int $page): CreditPage;
}

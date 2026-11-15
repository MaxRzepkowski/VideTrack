<?php

namespace App\Catalog\Contracts;

use App\Catalog\Data\FranchiseDetails;

/**
 * Capability: the provider groups titles into franchises — wider than a
 * series: the line itself and everything else under its name (spin-offs,
 * side games). Shown from the provider as it answers; nothing is stored and
 * nothing is followed.
 */
interface SupportsFranchises
{
    public function franchise(string $ref, string $locale): FranchiseDetails;
}

<?php

namespace App\Catalog\Contracts;

use App\Catalog\Data\PlatformDetails;

/**
 * Capability: the provider describes the machines titles are released on (a
 * console, a handheld, a computer) and their titles. Shown from the provider
 * as it answers; nothing is stored and nothing is followed — a platform gets
 * hundreds of games a year.
 */
interface SupportsPlatforms
{
    public function platform(string $ref, string $locale): PlatformDetails;
}

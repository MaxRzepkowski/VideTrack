<?php

namespace App\Catalog\Contracts;

use App\Catalog\Data\PersonDetails;

/**
 * Capability: the provider serves a person page (a creator profile with a
 * filmography). Distinct from SupportsPeopleSearch — a provider can back a
 * page reachable from a title's credits without offering free-text people
 * search (e.g. Hardcover authors).
 */
interface SupportsPersonPage
{
    public function person(string $ref, string $locale): PersonDetails;
}

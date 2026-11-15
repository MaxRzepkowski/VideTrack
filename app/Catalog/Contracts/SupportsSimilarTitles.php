<?php

namespace App\Catalog\Contracts;

use App\Catalog\Data\MediaSummary;

/**
 * Capability: the provider names the titles most like a given one in an
 * answer of its own, apart from the title's details. Asked only for the
 * title's page: a provider whose details already carry them (`similar`) has
 * no need of it, and a refresh of the catalog never asks.
 */
interface SupportsSimilarTitles
{
    /** @return list<MediaSummary> the most similar first */
    public function similarTo(string $type, string $ref, string $locale): array;
}

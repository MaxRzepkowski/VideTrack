<?php

namespace App\Catalog\Contracts;

use App\Catalog\Data\CollectionDetails;

/**
 * Capability: the provider curates collections of titles (film sagas, game and book series).
 * Details of a title belonging to one name it in `MediaDetails::$collection`.
 */
interface SupportsCollections
{
    public function collection(string $ref, string $locale): CollectionDetails;
}

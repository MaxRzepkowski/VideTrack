<?php

namespace App\Catalog\Contracts;

use App\Catalog\RelatedReleaseKind;

/**
 * Capability: a title's details list its releases of its own (a game's DLC,
 * remakes, remasters) with their dates, in `MediaDetails::$relatedReleases`,
 * so a calendar can show them beside the title.
 */
interface SupportsRelatedReleases
{
    /**
     * @return list<RelatedReleaseKind> the kinds it lists
     */
    public function relatedReleaseKinds(): array;
}

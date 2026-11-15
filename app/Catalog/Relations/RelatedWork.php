<?php

namespace App\Catalog\Relations;

use App\Catalog\MediaKind;

/**
 * A work an outside source ties to a title, before it is found in our
 * catalogue: what kind of work it is and the ids other catalogues know it by.
 */
final readonly class RelatedWork
{
    /**
     * @param  array<string, string>  $externalIds  catalogue => id, as the id resolver reads them
     */
    public function __construct(
        public TitleRelation $relation,
        public MediaKind $kind,
        public array $externalIds,
    ) {}
}

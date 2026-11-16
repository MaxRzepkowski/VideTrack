<?php

namespace App\Catalog\Relations;

/**
 * A work an outside source ties to a title, and how it stands to it.
 */
final readonly class RelatedWork
{
    public function __construct(
        public TitleRelation $relation,
        public CatalogWork $work,
    ) {}
}

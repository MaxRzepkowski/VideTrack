<?php

namespace App\Catalog;

use App\Catalog\Contracts\SupportsCollectionSearch;
use App\Catalog\Contracts\SupportsCompanySearch;
use App\Catalog\Contracts\SupportsPeopleSearch;

/**
 * The search scopes that are not a provider's titles: whatever provider can
 * search people, collections or companies serves them. The value is the
 * scope key the search page and the API use.
 */
enum EntitySearchScope: string
{
    case Person = 'person';
    case Collection = 'collection';
    case Company = 'company';

    /** @return class-string */
    public function contract(): string
    {
        return match ($this) {
            self::Person => SupportsPeopleSearch::class,
            self::Collection => SupportsCollectionSearch::class,
            self::Company => SupportsCompanySearch::class,
        };
    }
}

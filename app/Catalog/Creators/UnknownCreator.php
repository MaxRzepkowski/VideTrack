<?php

namespace App\Catalog\Creators;

use RuntimeException;

/**
 * No provider here serves a page for this creator — an unknown provider, or
 * one without pages of that kind. Nothing was asked of any service, so this
 * is not the service's "not found"; to the visitor it is the same 404.
 */
final class UnknownCreator extends RuntimeException
{
    public static function of(CreatorRef $creator): self
    {
        return new self("No page serves [{$creator->key()}].");
    }
}

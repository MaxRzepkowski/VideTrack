<?php

namespace App\Suggestions;

use App\Suggestions\Contracts\SuggestionSource;
use App\Support\ServiceRegistry;

/**
 * The suggestion shelves of this instance, in the order the page shows them.
 *
 * @extends ServiceRegistry<SuggestionSource>
 */
final class SuggestionSources extends ServiceRegistry
{
    protected function kind(): string
    {
        return 'suggestion source';
    }
}

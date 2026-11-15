<?php

namespace App\Integrations\Import\Data;

/**
 * What the user ticked in the manifest. Sources without selection ignore it
 * and import everything.
 */
final readonly class ImportSelection
{
    /**
     * @param  list<string>  $categories
     * @param  list<string>  $listIds
     */
    public function __construct(
        public array $categories = [],
        public array $listIds = [],
    ) {}

    public static function everything(): self
    {
        return new self;
    }

    public function has(string $category): bool
    {
        return in_array($category, $this->categories, true);
    }
}

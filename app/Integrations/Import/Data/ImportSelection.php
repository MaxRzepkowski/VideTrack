<?php

namespace App\Integrations\Import\Data;

/**
 * What the user ticked in the manifest. Sources without selection ignore it
 * and import everything.
 */
final readonly class ImportSelection
{
    /** The longest category key or list id a selection carries. */
    public const KEY_LENGTH = 64;

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

    /** A name from the file as a key a selection can carry back. */
    public static function key(string $name): string
    {
        return mb_substr($name, 0, self::KEY_LENGTH);
    }

    public function has(string $category): bool
    {
        return in_array($category, $this->categories, true);
    }
}

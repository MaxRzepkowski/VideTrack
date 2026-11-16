<?php

namespace App\Http;

use App\Catalog\MediaTypeRegistry;

/**
 * Where a card leads, whatever its depth: a root to its title page, a part of
 * a hierarchy (a season, an episode) to its own page under the root's. Apart
 * from {@see TitlePages}, which answers for roots by route name alone: a
 * part's depth takes the type registry.
 */
final readonly class CardPages
{
    /** Below a root, the ref adds a number per level: season 2 of show 10 is "10:2". */
    private const LEVEL_PARAMETERS = ['number', 'episode'];

    public function __construct(private MediaTypeRegistry $types) {}

    /** The page of a card, as a path; a type this instance does not know leads to search. */
    public function path(string $type, string $ref): string
    {
        if (! $this->types->has($type)) {
            return route('search', absolute: false);
        }

        $depth = count($this->types->lineage($type)) - 1;

        if ($depth === 0) {
            return TitlePages::path($type, $ref);
        }

        $parts = explode(':', $ref);
        $parameters = ['ref' => $parts[0]];

        foreach (array_slice(self::LEVEL_PARAMETERS, 0, $depth) as $level => $name) {
            $parameters[$name] = $parts[$level + 1] ?? 1;
        }

        return route("{$type}.details", $parameters, absolute: false);
    }
}

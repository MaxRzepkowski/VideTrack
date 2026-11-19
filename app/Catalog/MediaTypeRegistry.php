<?php

namespace App\Catalog;

use InvalidArgumentException;

/**
 * Registry of all media types known to this instance.
 * Registered as a singleton; definitions are added in a service provider.
 */
final class MediaTypeRegistry
{
    /** @var array<string, MediaTypeDefinition> */
    private array $definitions = [];

    public function register(MediaTypeDefinition $definition): void
    {
        $this->definitions[$definition->code] = $definition;
    }

    public function has(string $code): bool
    {
        return isset($this->definitions[$code]);
    }

    public function get(string $code): MediaTypeDefinition
    {
        if (! $this->has($code)) {
            throw new InvalidArgumentException("Unknown media type [{$code}].");
        }

        return $this->definitions[$code];
    }

    /**
     * The type and every type above it in the hierarchy, root last
     * (episode → season → show); a type its registration does not place
     * under a known parent is its own root.
     *
     * @return non-empty-list<MediaTypeDefinition>
     */
    public function lineage(string $code): array
    {
        $lineage = [$this->get($code)];

        while (($parent = end($lineage)->parentType) !== null && $this->has($parent) && count($lineage) <= count($this->definitions)) {
            $lineage[] = $this->definitions[$parent];
        }

        return $lineage;
    }

    /**
     * @return array<string, MediaTypeDefinition>
     */
    public function all(): array
    {
        return $this->definitions;
    }

    /**
     * The given codes in the registry's order — the one order every type
     * filter shows. Codes that are not media types here are left out.
     *
     * @param  iterable<string>  $codes
     * @return list<string>
     */
    public function ordered(iterable $codes): array
    {
        $present = array_flip(is_array($codes) ? $codes : iterator_to_array($codes, false));

        return array_values(array_filter(array_keys($this->definitions), fn (string $code): bool => isset($present[$code])));
    }

    /**
     * Types a title is stored under as a whole (a film, a show; not a season).
     *
     * @return list<string>
     */
    public function rootCodes(): array
    {
        return array_keys(array_filter($this->definitions, fn (MediaTypeDefinition $d): bool => $d->isRoot()));
    }

    /**
     * Types a title is searched for and kept under as a whole (a film, a
     * show, a game): the types a statistic or a tile is counted per.
     *
     * @return list<string>
     */
    public function titleCodes(): array
    {
        return array_keys(array_filter($this->definitions, fn (MediaTypeDefinition $d): bool => $d->isRoot() && $d->searchable));
    }

    /**
     * Types a play is logged on directly (a film, an episode, a game; not a
     * show or a season, which their parts finish).
     *
     * @return list<string>
     */
    public function playableCodes(): array
    {
        return array_keys(array_filter($this->definitions, fn (MediaTypeDefinition $d): bool => $d->playable));
    }

    /**
     * @return list<string>
     */
    public function searchableCodes(): array
    {
        return array_keys(
            array_filter($this->definitions, fn (MediaTypeDefinition $d) => $d->searchable)
        );
    }

    /**
     * Types whose titles are watched part by part: a root with children
     * (a show, not its seasons; not a film).
     *
     * @return list<string>
     */
    public function containerRootCodes(): array
    {
        return array_keys(
            array_filter($this->definitions, fn (MediaTypeDefinition $d): bool => $d->isRoot() && $d->childType !== null)
        );
    }

    /**
     * What the companies of a provider make: the first root type it serves
     * that can be searched, in the registry's order (a film provider's
     * studios make films; a game provider's companies make games). Null
     * for a provider no type is served by here.
     */
    public function rootServedBy(string $provider): ?string
    {
        foreach ($this->definitions as $code => $definition) {
            if ($definition->provider === $provider && $definition->isRoot() && $definition->searchable) {
                return $code;
            }
        }

        return null;
    }
}

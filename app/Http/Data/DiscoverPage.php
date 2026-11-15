<?php

namespace App\Http\Data;

use App\Catalog\Data\DiscoverQuery;

/**
 * The discover page as built: its props, the canonical address an aged
 * link should land on instead, or nothing at all — a link naming a genre the
 * provider does not have.
 */
final readonly class DiscoverPage
{
    /**
     * @param  array<string, mixed>  $props
     * @param  array<string, mixed>|null  $redirectTo  `discover` route parameters
     */
    private function __construct(
        public array $props = [],
        public ?array $redirectTo = null,
        public bool $notFound = false,
    ) {}

    /**
     * @param  array<string, mixed>  $props
     */
    public static function render(array $props): self
    {
        return new self(props: $props);
    }

    /**
     * The canonical address of the query — with other filters or another
     * page — for an aged link to land on.
     *
     * @param  array<array-key, mixed>|null  $filters
     */
    public static function redirect(DiscoverQuery $query, ?array $filters = null, ?int $page = null): self
    {
        $filters ??= $query->filters;
        $page ??= $query->page;

        return new self(redirectTo: array_filter([
            'type' => $query->type,
            'f' => $filters === [] ? null : $filters,
            'page' => $page === 1 ? null : $page,
        ], fn (mixed $value): bool => $value !== null));
    }

    public static function notFound(): self
    {
        return new self(notFound: true);
    }
}

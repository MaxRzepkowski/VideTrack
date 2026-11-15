<?php

namespace App\Support;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use JsonSerializable;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Where a paged grid stands — the shape every pager on the front reads
 * (`types/pagination.ts`, PageInfo).
 */
#[TypeScript]
final readonly class PageInfo implements JsonSerializable
{
    public function __construct(
        public int $currentPage,
        public int $totalPages,
        public int $total,
    ) {}

    /**
     * @template TKey of array-key
     * @template TValue
     *
     * @param  LengthAwarePaginator<TKey, TValue>  $page
     */
    public static function of(LengthAwarePaginator $page): self
    {
        return new self($page->currentPage(), $page->lastPage(), $page->total());
    }

    /** One empty page: what a listing that could not be read shows. */
    public static function empty(): self
    {
        return new self(1, 1, 0);
    }

    /**
     * @return array{
     *     currentPage: int,
     *     totalPages: int,
     *     total: int
     * }
     */
    public function jsonSerialize(): array
    {
        return [
            'currentPage' => $this->currentPage,
            'totalPages' => $this->totalPages,
            'total' => $this->total,
        ];
    }
}

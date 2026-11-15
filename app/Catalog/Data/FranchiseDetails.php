<?php

namespace App\Catalog\Data;

use JsonSerializable;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * A franchise and its titles, split as a reader asks of it: the titles of its
 * series (the line one plays through) and the rest under its name.
 */
#[TypeScript]
final readonly class FranchiseDetails implements JsonSerializable
{
    /**
     * @param  list<MediaSummary>  $series  titles of a series of the franchise, in release order
     * @param  list<MediaSummary>  $others  the rest under its name (spin-offs, side games), in release order
     */
    public function __construct(
        public string $ref,
        public string $name,
        public array $series = [],
        public array $others = [],
    ) {}

    /** @return list<MediaSummary> every title of it, for the cards' states */
    public function titles(): array
    {
        return [...$this->series, ...$this->others];
    }

    /**
     * @return array{
     *     ref: string,
     *     name: string,
     *     series: list<MediaSummary>,
     *     others: list<MediaSummary>
     * }
     */
    public function jsonSerialize(): array
    {
        return [
            'ref' => $this->ref,
            'name' => $this->name,
            'series' => $this->series,
            'others' => $this->others,
        ];
    }
}

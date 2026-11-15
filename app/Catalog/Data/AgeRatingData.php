<?php

namespace App\Catalog\Data;

use JsonSerializable;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One board's age rating of a game (PEGI 18, ESRB M): unlike a film's
 * classification it belongs to a board, not to a country, and says why
 * (the content it found) in the board's own words.
 */
#[TypeScript]
final readonly class AgeRatingData implements JsonSerializable
{
    /**
     * @param  list<string>  $descriptors  the content behind the rating (Blood and Gore, Strong Language)
     */
    public function __construct(
        public string $board,
        public string $rating,
        public array $descriptors = [],
        /** The board's own summary of the content, where it writes one (ESRB). */
        public ?string $synopsis = null,
    ) {}

    /**
     * @return array{
     *     board: string,
     *     rating: string,
     *     descriptors: list<string>,
     *     synopsis: string|null
     * }
     */
    public function jsonSerialize(): array
    {
        return [
            'board' => $this->board,
            'rating' => $this->rating,
            'descriptors' => $this->descriptors,
            'synopsis' => $this->synopsis,
        ];
    }
}

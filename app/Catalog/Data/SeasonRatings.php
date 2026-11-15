<?php

namespace App\Catalog\Data;

use JsonSerializable;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** The community ratings of one season, episode by episode. */
#[TypeScript]
final readonly class SeasonRatings implements JsonSerializable
{
    /**
     * @param  list<EpisodeRating>  $episodes
     */
    public function __construct(
        public int $season,
        public array $episodes,
    ) {}

    /**
     * @return array{
     *     season: int,
     *     episodes: list<EpisodeRating>
     * }
     */
    public function jsonSerialize(): array
    {
        return [
            'season' => $this->season,
            'episodes' => $this->episodes,
        ];
    }
}

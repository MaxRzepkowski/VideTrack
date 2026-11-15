<?php

namespace App\Catalog\Data;

use JsonSerializable;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** One point of the episode-quality chart; unrated and unaired episodes carry a null rating. */
#[TypeScript]
final readonly class EpisodeRating implements JsonSerializable
{
    public function __construct(
        public int $episode,
        public string $title,
        public ?float $rating,
        public ?string $airDate,
    ) {}

    /**
     * @return array{
     *     episode: int,
     *     title: string,
     *     rating: float|null,
     *     airDate: string|null
     * }
     */
    public function jsonSerialize(): array
    {
        return [
            'episode' => $this->episode,
            'title' => $this->title,
            'rating' => $this->rating,
            'airDate' => $this->airDate,
        ];
    }
}

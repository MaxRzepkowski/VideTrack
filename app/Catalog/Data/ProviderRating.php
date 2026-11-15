<?php

namespace App\Catalog\Data;

use JsonSerializable;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** A score one audience gave at the provider (critics, players), on the app's 0–10 scale, and how many gave it. */
#[TypeScript]
final readonly class ProviderRating implements JsonSerializable
{
    public function __construct(
        public float $score,
        public int $count,
    ) {}

    /**
     * @return array{
     *     score: float,
     *     count: int
     * }
     */
    public function jsonSerialize(): array
    {
        return [
            'score' => $this->score,
            'count' => $this->count,
        ];
    }
}

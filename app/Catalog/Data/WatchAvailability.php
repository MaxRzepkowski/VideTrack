<?php

namespace App\Catalog\Data;

use JsonSerializable;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Where a title can be watched in one region. The data comes from JustWatch
 * (via the provider) — the UI must attribute JustWatch wherever it renders
 * this, and `link` points at the provider's own watch page (no deep links).
 */
#[TypeScript]
final readonly class WatchAvailability implements JsonSerializable
{
    public function __construct(
        /** ISO 3166-1 region the offers apply to. */
        public string $region,
        public ?string $link = null,
        /** @var list<WatchProviderData> subscription streaming */
        public array $stream = [],
        /** @var list<WatchProviderData> free to watch, with ads or without */
        public array $free = [],
        /** @var list<WatchProviderData> */
        public array $rent = [],
        /** @var list<WatchProviderData> */
        public array $buy = [],
    ) {}

    public function isEmpty(): bool
    {
        return $this->stream === [] && $this->free === [] && $this->rent === [] && $this->buy === [];
    }

    /**
     * @return array{
     *     region: string,
     *     link: string|null,
     *     stream: list<WatchProviderData>,
     *     free: list<WatchProviderData>,
     *     rent: list<WatchProviderData>,
     *     buy: list<WatchProviderData>
     * }
     */
    public function jsonSerialize(): array
    {
        return [
            'region' => $this->region,
            'link' => $this->link,
            'stream' => $this->stream,
            'free' => $this->free,
            'rent' => $this->rent,
            'buy' => $this->buy,
        ];
    }
}

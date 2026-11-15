<?php

namespace App\Catalog\Data;

use App\Catalog\GameStore;
use JsonSerializable;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** A game's own page in one store or subscription. */
#[TypeScript]
final readonly class StoreLink implements JsonSerializable
{
    public function __construct(
        public GameStore $store,
        public string $url,
    ) {}

    /**
     * @return array{
     *     store: value-of<GameStore>,
     *     url: string,
     *     subscription: bool
     * }
     */
    public function jsonSerialize(): array
    {
        return [
            'store' => $this->store->value,
            'url' => $this->url,
            'subscription' => $this->store->isSubscription(),
        ];
    }
}

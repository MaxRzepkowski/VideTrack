<?php

namespace Tests\Fixtures\WireShapes\Described;

use JsonSerializable;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * @phpstan-import-type Bag from Card
 * @phpstan-import-type Bag from Card as Pocket
 */
#[TypeScript]
final readonly class Shelf implements JsonSerializable
{
    /**
     * @param  list<Card>  $cards
     * @param  Bag  $bag
     */
    public function __construct(public array $cards, public array $bag) {}

    /**
     * @return array{cards: list<Card>, bag: Bag, pocket: Pocket}
     */
    public function jsonSerialize(): array
    {
        return ['cards' => $this->cards, 'bag' => $this->bag, 'pocket' => $this->bag];
    }
}

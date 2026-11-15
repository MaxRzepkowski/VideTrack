<?php

namespace App\Catalog\Data;

use JsonSerializable;
use Spatie\TypeScriptTransformer\Attributes\Optional;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One choice inside a discover filter. Options travel as an ordered LIST, not
 * as a value => label map: the values are provider ids (numeric strings), and
 * iterating such an object in JavaScript would re-order them numerically no
 * matter what order PHP sent — a map keyed 0,1,2… would not even survive
 * json_encode as an object. The provider's order is part of the contract
 * (streaming services by display priority, platforms by name).
 */
#[TypeScript]
final readonly class DiscoverFilterOption implements JsonSerializable
{
    /**
     * @param  string|null  $ascending  the same axis run the other way, where
     *                                  the provider offers both. A sort option
     *                                  carries it so the page can show one axis
     *                                  with a direction toggle instead of two
     *                                  entries ("Newest" / "Oldest"); `value` is
     *                                  then the descending run. Null means the
     *                                  axis only reads one way — nobody browses
     *                                  by least popular.
     */
    public function __construct(
        public string $value,
        public string $label,
        #[Optional]
        public ?string $ascending = null,
    ) {}

    /**
     * @return array{
     *     value: string,
     *     label: string,
     *     ascending?: string
     * }
     */
    public function jsonSerialize(): array
    {
        $option = [
            'value' => $this->value,
            'label' => $this->label,
        ];

        // Left out where it does not apply: genres and watch providers run to
        // hundreds of options, and a null per option is payload for nothing.
        if ($this->ascending !== null) {
            $option['ascending'] = $this->ascending;
        }

        return $option;
    }
}

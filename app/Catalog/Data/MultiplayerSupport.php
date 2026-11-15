<?php

namespace App\Catalog\Data;

use App\Catalog\MultiplayerMode;
use JsonSerializable;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** One way a game is played together, and for how many at most where the provider says. */
#[TypeScript]
final readonly class MultiplayerSupport implements JsonSerializable
{
    public function __construct(
        public MultiplayerMode $mode,
        public ?int $players = null,
    ) {}

    /**
     * @return array{
     *     mode: value-of<MultiplayerMode>,
     *     players: int|null
     * }
     */
    public function jsonSerialize(): array
    {
        return [
            'mode' => $this->mode->value,
            'players' => $this->players,
        ];
    }
}

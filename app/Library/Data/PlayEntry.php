<?php

namespace App\Library\Data;

use App\Enums\PlayOutcome;
use App\Models\Play;
use JsonSerializable;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One closed attempt in a title's history, as the plays endpoint lists it and
 * the history panel edits it: completed or dropped, with the cost recorded
 * for types that count one (games: minutes, books: pages).
 */
#[TypeScript]
final readonly class PlayEntry implements JsonSerializable
{
    public function __construct(
        public int $id,
        public string $watchedAt,
        public PlayOutcome $outcome,
        /** In the type's progress unit; null where none was kept. */
        public ?int $amount,
        public int $cycle,
    ) {}

    public static function of(Play $play): self
    {
        return new self(
            id: $play->id,
            watchedAt: $play->watched_at->toIso8601String(),
            outcome: $play->outcome,
            amount: $play->amount,
            cycle: $play->cycleNumber(),
        );
    }

    /**
     * @return array{
     *     id: int,
     *     watchedAt: string,
     *     outcome: value-of<PlayOutcome>,
     *     amount: int|null,
     *     cycle: int
     * }
     */
    public function jsonSerialize(): array
    {
        return [
            'id' => $this->id,
            'watchedAt' => $this->watchedAt,
            'outcome' => $this->outcome->value,
            'amount' => $this->amount,
            'cycle' => $this->cycle,
        ];
    }
}

<?php

namespace App\Library\Data;

use JsonSerializable;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** One run through a show, as its watched modal lists it. */
#[TypeScript]
final readonly class WatchCycleSummary implements JsonSerializable
{
    /**
     * Only the run under way can be cancelled, and never the first: it was
     * not started, so there is nothing to undo.
     */
    public bool $canCancel;

    public function __construct(
        public int $number,
        /** Null for the first run, which nobody starts: it is implicit. */
        public ?string $startedAt,
        public int $watchedEpisodes,
        public int $airedEpisodes,
        /** Every entry of the run, skips and drops included: what cancelling it would delete. */
        public int $plays,
        public bool $completed,
        public ?string $firstPlayAt,
        public ?string $lastPlayAt,
        public bool $isActive,
    ) {
        $this->canCancel = $isActive && $number > 1;
    }

    /** The first run of a show nobody touched: nothing watched, nothing to cancel. */
    public static function untouched(): self
    {
        return new self(1, null, 0, 0, 0, false, null, null, isActive: true);
    }

    /**
     * @return array{
     *     number: int,
     *     startedAt: string|null,
     *     watchedEpisodes: int,
     *     airedEpisodes: int,
     *     plays: int,
     *     completed: bool,
     *     firstPlayAt: string|null,
     *     lastPlayAt: string|null,
     *     isActive: bool,
     *     canCancel: bool
     * }
     */
    public function jsonSerialize(): array
    {
        return [
            'number' => $this->number,
            'startedAt' => $this->startedAt,
            'watchedEpisodes' => $this->watchedEpisodes,
            'airedEpisodes' => $this->airedEpisodes,
            'plays' => $this->plays,
            'completed' => $this->completed,
            'firstPlayAt' => $this->firstPlayAt,
            'lastPlayAt' => $this->lastPlayAt,
            'isActive' => $this->isActive,
            'canCancel' => $this->canCancel,
        ];
    }
}

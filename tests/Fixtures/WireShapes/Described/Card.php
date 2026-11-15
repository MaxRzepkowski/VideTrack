<?php

namespace Tests\Fixtures\WireShapes\Described;

use JsonSerializable;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Tests\Fixtures\WireShapes\Described\Kinds\Mood;

/**
 * Sends something else than it holds: `internal` stays home, `counts` and
 * `nested` exist only on the wire.
 *
 * @phpstan-type Bag array{
 *     note?: string,
 *     mood?: value-of<Mood>,
 *     ...<string, mixed>
 * }
 */
#[TypeScript]
final readonly class Card implements JsonSerializable
{
    /**
     * @param  list<string>  $tags
     * @param  Bag  $bag
     */
    public function __construct(
        public string $title,
        public ?int $year,
        public array $tags,
        public Tone $tone,
        public Grade $grade,
        public ?Badge $badge,
        public array $bag,
        public string $internal,
    ) {}

    /**
     * @return array{
     *     title: string,
     *     year: int|null,
     *     tags: list<string>,
     *     tone: value-of<Tone>,
     *     grade: value-of<Grade>,
     *     badge: Badge|null,
     *     bag: Bag,
     *     counts: array<string, int>,
     *     flagged?: bool,
     *     nested: array{anything: mixed}
     * }
     */
    public function jsonSerialize(): array
    {
        return [
            'title' => $this->title,
            'year' => $this->year,
            'tags' => $this->tags,
            'tone' => $this->tone->value,
            'grade' => $this->grade->value,
            'badge' => $this->badge,
            'bag' => $this->bag,
            'counts' => [],
            'nested' => ['anything' => null],
        ];
    }
}

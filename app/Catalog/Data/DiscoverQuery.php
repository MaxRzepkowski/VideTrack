<?php

namespace App\Catalog\Data;

use App\Catalog\Creators\CreatorKind;

/**
 * What the discover page was asked for. `filters` is the raw `f` parameter:
 * aged links are expected, so it is normalised against the provider's own
 * filter definitions, never validated away.
 */
final readonly class DiscoverQuery
{
    /** Not a media type: the people grid shares the type select. */
    public const PEOPLE = CreatorKind::PERSON_CARD;

    /**
     * @param  array<array-key, mixed>|null  $filters
     */
    public function __construct(
        public string $type,
        public ?string $genre = null,
        public ?array $filters = null,
        public int $page = 1,
    ) {}

    public function isPeople(): bool
    {
        return $this->type === self::PEOPLE;
    }
}

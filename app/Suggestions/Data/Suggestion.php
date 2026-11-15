<?php

namespace App\Suggestions\Data;

use App\Catalog\Data\MediaSummary;
use App\Suggestions\Contracts\SuggestionSource;
use JsonSerializable;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * A title put forward, with the facts that put it there.
 */
#[TypeScript]
final readonly class Suggestion implements JsonSerializable
{
    /**
     * @param  list<string>  $reason  the facts, worded, each one its own line under the card
     * @param  string|null  $addedOn  the day the user reached for the title, where the source knows one
     * @param  list<string>  $origins  which of its source's ways in brought it here ({@see SuggestionSource::origins()})
     */
    public function __construct(
        public MediaSummary $media,
        public array $reason,
        public array $origins = [],
        public ?string $addedOn = null,
    ) {}

    /**
     * What its card shows: where it came from and when stay with the shelf.
     *
     * @return array{media: MediaSummary, reason: list<string>}
     */
    public function jsonSerialize(): array
    {
        return ['media' => $this->media, 'reason' => $this->reason];
    }
}

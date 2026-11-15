<?php

namespace App\Suggestions\Data;

use LogicException;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * What a kind's suggestions gather under (a collection), on the kind's page.
 */
#[TypeScript]
final readonly class SuggestionSubject
{
    public function __construct(
        public string $key,
        public string $title,
        /** Where the user stands with the subject ("3 of 5 watched"). */
        public ?string $caption,
        /** How much of it the user is through, where that is counted. */
        public ?int $finished,
        public ?int $total,
        /** The subject's own picture, else its first title's. */
        public ?string $posterPath,
        /** Titles put forward under it. */
        public int $count,
    ) {}

    /** A group listed as a subject: its source gives every one a key and a name. */
    public static function of(SuggestionGroup $group): self
    {
        return new self(
            key: $group->key ?? throw new LogicException('A subject on its shelf has a key.'),
            title: $group->title ?? throw new LogicException('A subject on its shelf has a name.'),
            caption: $group->caption,
            finished: $group->finished,
            total: $group->total,
            posterPath: $group->posterPath ?? $group->suggestions[0]->media->posterPath,
            count: count($group->suggestions),
        );
    }
}

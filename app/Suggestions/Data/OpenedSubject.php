<?php

namespace App\Suggestions\Data;

use LogicException;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * The subject a kind's page is opened on, with the way to its own page.
 */
#[TypeScript]
final readonly class OpenedSubject
{
    public function __construct(
        public string $key,
        public string $title,
        public ?string $caption,
        public ?string $href,
    ) {}

    /** The group the address named, found by its key. */
    public static function of(SuggestionGroup $group): self
    {
        return new self(
            key: $group->key ?? throw new LogicException('An opened subject has a key.'),
            title: $group->title ?? throw new LogicException('An opened subject has a name.'),
            caption: $group->caption,
            href: $group->href,
        );
    }
}

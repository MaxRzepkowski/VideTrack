<?php

namespace App\Catalog\Data;

use App\Catalog\Creators\CreatorKind;
use JsonSerializable;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * A creator credited on a title (book author, game studio, TV network…) with
 * an optional link to their own page. `ref`/`provider` are null when the
 * source provider exposes no reachable page (MAL authors, ComicVine creators)
 * — the frontend then renders the name as plain text. `kind` picks the target
 * page: a person's, a company's or a network's.
 */
#[TypeScript]
final readonly class CreatorLink implements JsonSerializable
{
    public function __construct(
        public string $name,
        public CreatorKind $kind,
        public ?string $ref = null,
        public ?string $provider = null,
        /** Wordmark, where the entity has one (studios and networks); people are shown by name. */
        public ?string $logoPath = null,
    ) {}

    /**
     * @return array{
     *     name: string,
     *     kind: value-of<CreatorKind>,
     *     ref: string|null,
     *     provider: string|null,
     *     logoPath: string|null
     * }
     */
    public function jsonSerialize(): array
    {
        return [
            'name' => $this->name,
            'kind' => $this->kind->value,
            'ref' => $this->ref,
            'provider' => $this->provider,
            'logoPath' => $this->logoPath,
        ];
    }
}

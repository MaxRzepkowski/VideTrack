<?php

namespace App\Catalog\Relations;

use App\Catalog\Data\MediaSummary;
use JsonSerializable;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Another title as it stands to the one asked about, ready for a card.
 */
#[TypeScript]
final readonly class TitleTie implements JsonSerializable
{
    public function __construct(
        public TitleRelation $relation,
        public MediaSummary $media,
        /** The provider collection the other title is a part of, where that is known. */
        public ?string $collectionRef = null,
    ) {}

    /**
     * The card with what it says of the tie; nothing for a title that only
     * shares the universe.
     *
     * @return array{
     *     relation: value-of<TitleRelation>,
     *     label: string|null,
     *     media: MediaSummary
     * }
     */
    public function jsonSerialize(): array
    {
        return [
            'relation' => $this->relation->value,
            'label' => $this->relation->label(),
            'media' => $this->media,
        ];
    }
}

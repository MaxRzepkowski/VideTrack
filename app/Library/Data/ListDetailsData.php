<?php

namespace App\Library\Data;

use App\Catalog\Data\CollectionRelations;
use App\Enums\FollowMode;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * The header of a list's page.
 */
#[TypeScript]
final readonly class ListDetailsData
{
    /**
     * @param  array{provider: string, ref: string}|null  $collection  the provider collection the list mirrors, if it is one: the page's refresh and bell address it, never parse the key
     * @param  list<string>  $backdrops  the saga's own gallery; none for a list a user typed out
     * @param  list<string>  $posters
     * @param  array{id: int, name: string, username: string, avatar: string|null}|null  $owner  null for an ownerless system list (a provider collection), which the instance itself authors
     */
    public function __construct(
        public int $id,
        /** As written; null for the backlog, which has no title of its own. */
        public ?string $title,
        public ?string $description,
        public ?string $systemKey,
        /** The title as shown: the backlog's in the reader's language, a user list's as written. */
        public string $displayTitle,
        public ?array $collection,
        public bool $isPublic,
        /** A collection list's cover: the provider collection's backdrop. */
        public ?string $backdropPath,
        public array $backdrops,
        public array $posters,
        public ?array $owner,
        public bool $isOwner,
        public bool $canDelete,
        /** A provider collection's bell; plain lists have no provider events, so none. */
        public ?FollowMode $followMode,
        /** What a provider collection is tied to beside its parts (its series, subseries, spin-offs); their pages are the same provider's. */
        public ?CollectionRelations $collectionRelations = null,
    ) {}
}

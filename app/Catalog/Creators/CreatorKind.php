<?php

namespace App\Catalog\Creators;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Whoever makes titles and can be followed: a person, a studio or publisher,
 * a TV network, a provider collection (a film saga, a book or game series).
 * One mechanism for all four — what differs between them is said here or by
 * the strategy that serves the kind, never by a copy of the pipeline.
 */
#[TypeScript]
enum CreatorKind: string
{
    case Person = 'person';
    case Company = 'company';
    case Network = 'network';
    case Collection = 'collection';

    /**
     * Not media types: the `type` a creator's card carries among titles
     * (search results, list positions, discover rows), a person's portrait or
     * a company's logo — a network says so in the card's `creatorKind`.
     */
    public const PERSON_CARD = 'person';

    public const COMPANY_CARD = 'company';
}

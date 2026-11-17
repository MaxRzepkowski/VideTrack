<?php

namespace App\Catalog;

/**
 * Code-level definition of a media type. New media types are
 * added by registering another definition — the core never hardcodes types.
 */
final readonly class MediaTypeDefinition
{
    public function __construct(
        public string $code,
        public string $provider,
        /** Type code of the parent in the hierarchy (season → show), null for roots. */
        public ?string $parentType = null,
        /** Type code of the children in the hierarchy (show → season), null for leaves. */
        public ?string $childType = null,
        /** Whether a play can be logged directly on this type (movie, episode). */
        public bool $playable = false,
        /** Whether the type appears as a standalone result in search/discover. */
        public bool $searchable = false,
        /**
         * Child number excluded from root-level cascades and derived status
         * (season 0 / Specials for shows); null = no specials
         * semantics. Explicit interaction with the special child still works.
         */
        public ?int $specialsChildNumber = null,
        /**
         * Whether the user can put a dropped / on-hold status on this type
         * (roots only: shows, games — not one-shot movies).
         */
        public bool $userStatus = false,
        /**
         * Unit of user-recorded progress for continuously consumed flat
         * types (Minutes for games, Pages for books); null = progress is
         * either derived (shows) or does not apply (movies). Types with a
         * unit get session logging, the manual in-progress status and the
         * progress editor.
         */
        public ?ProgressUnit $progressUnit = null,
        /**
         * Translation key naming a group of titles of this type ("TV shows"),
         * for text that sorts titles by type — the daily digest's sections.
         * Parts of a hierarchy go under their root and need none.
         */
        public ?string $pluralLabel = null,
        /** The emoji heading that group in plain-text channels. */
        public ?string $emoji = null,
        /**
         * The type's palette colour (`--color-media-*` in app.css) for
         * channels that cannot read the stylesheet — a Discord embed. Parts
         * of a hierarchy take their root's.
         */
        public ?string $color = null,
    ) {}

    public function isRoot(): bool
    {
        return $this->parentType === null;
    }

    /**
     * A title released whole, once (a film): watched in one go, with no parts
     * and no counter. It opens in cinemas and comes home later — the two
     * moments a calendar and a creator's bell follow apart.
     */
    public function releasesWhole(): bool
    {
        return $this->playable && $this->childType === null && $this->progressUnit === null;
    }

    /** Whether a position of this type holds an open attempt of its own (on hold, in progress): roots that declare a status. */
    public function takesOpenStatus(): bool
    {
        return $this->isRoot() && $this->userStatus;
    }
}

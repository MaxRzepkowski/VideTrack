<?php

namespace App\Suggestions\Contracts;

use App\Catalog\Sets\SetKey;
use App\Models\User;
use App\Suggestions\Data\SuggestionGroup;

/**
 * One rule that finds titles a user has a reason to catch up on. It reads
 * only what is stored here: what a provider must list first, the source asks
 * for as sets, and the background task fetches them. A source never writes
 * and never notifies; its page is one the user opens on their own.
 */
interface SuggestionSource
{
    /** The shelf's heading, in the request's language. */
    public function title(): string;

    /** One sentence saying what puts a title on this shelf. */
    public function description(): string;

    /**
     * The ways a title gets onto this shelf, where the user may want only
     * some of them (added to a list, or only belled): key => label. Empty for
     * a source with one way in. A suggestion names its own in `origins`.
     *
     * @return array<string, string>
     */
    public function origins(): array;

    /**
     * The provider sets this source needs to answer for the user.
     *
     * @return list<SetKey>
     */
    public function wantedSets(User $user): array;

    /**
     * The user's suggestions, best first. A source whose suggestions share a
     * subject (a collection, a person) returns a group per subject; the rest
     * return one unnamed group.
     *
     * @return list<SuggestionGroup>
     */
    public function groups(User $viewer, string $locale): array;
}

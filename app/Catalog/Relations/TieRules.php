<?php

namespace App\Catalog\Relations;

use App\Catalog\ItemMetadata;
use App\Catalog\MediaKind;
use App\Models\MediaItem;

/**
 * What of the stored ties a title's page and the shelves actually show. The
 * outside source is kept as it answers; these rules make sense of it, so they
 * can change without asking it again.
 *
 *  - "Follows" between two films is dropped. Inside one story the provider's
 *    collection already orders the films; across a franchise the source mixes
 *    release order with story order, so one film "follows" another in a
 *    different branch and the obvious neighbours are missing.
 *  - Which way a continuation runs is read from the release dates, not from
 *    the source: editors file the same pair both ways round.
 *  - Each other title is shown once, by the closest thing known of it: a
 *    spin-off before a fellow title of the franchise, and that before a
 *    continuation, since within a franchise "follows" is the muddle above.
 *  - Of a franchise only titles known widely enough are shown: a universe
 *    also holds its shorts, specials and shop-floor tie-ins.
 *  - A fellow title of the franchise that is a part of this film's own
 *    provider collection is left to the collection, which already shows it.
 */
final readonly class TieRules
{
    /**
     * Ratings a film needs at its provider to be shown merely for sharing a
     * franchise. The shorts, TV specials and tie-ins of a universe typically
     * gather a few hundred, its films thousands.
     */
    public const UNIVERSE_MIN_FILM_VOTES = 600;

    /** The same bar for anything else: a show gathers far fewer ratings than a film. */
    public const UNIVERSE_MIN_VOTES = 200;

    /** Which word about a pair stands when the source has several; the lowest wins. */
    private const CLOSENESS = [
        TitleRelation::SpinOff->value => 0,
        TitleRelation::SpinOffOf->value => 0,
        TitleRelation::SameUniverse->value => 1,
        TitleRelation::Next->value => 2,
        TitleRelation::Previous->value => 2,
    ];

    /**
     * @param  list<TitleTie>  $ties  as stored, from this title's side
     * @return list<TitleTie> each other title once; the named ties first, then the franchise by release
     */
    public function settle(MediaItem $title, array $ties): array
    {
        $ownCollection = ItemMetadata::fromStored($title->metadata)->collection?->ref;
        $settled = [];

        foreach ($ties as $tie) {
            $key = $tie->media->key();
            $continuation = in_array($tie->relation, [TitleRelation::Next, TitleRelation::Previous], true);

            if ($continuation && $title->type === MediaKind::Movie->value && $tie->media->type === MediaKind::Movie->value) {
                continue;
            }

            if ($tie->relation === TitleRelation::SameUniverse && $ownCollection !== null && $tie->collectionRef === $ownCollection) {
                continue;
            }

            // No count means the title is stored here: the user's own, whatever its reach.
            if ($tie->relation === TitleRelation::SameUniverse && $tie->media->votes !== null && $tie->media->votes < $this->minVotes($tie)) {
                continue;
            }

            if ($continuation) {
                $tie = new TitleTie($this->direction($title, $tie), $tie->media, $tie->collectionRef);
            }

            if (! isset($settled[$key]) || self::CLOSENESS[$tie->relation->value] < self::CLOSENESS[$settled[$key]->relation->value]) {
                $settled[$key] = $tie;
            }
        }

        return self::inPageOrder(array_values($settled));
    }

    /**
     * The order a page lists ties in: by how the other title stands to this
     * one, as TitleRelation lists them (spin-offs, the original, what follows
     * it, what it follows, then the rest of the universe), each kind by release.
     *
     * @param  list<TitleTie>  $ties
     * @return list<TitleTie>
     */
    public static function inPageOrder(array $ties): array
    {
        $order = array_flip(array_map(fn (TitleRelation $relation): string => $relation->value, TitleRelation::cases()));

        usort($ties, fn (TitleTie $a, TitleTie $b): int => [$order[$a->relation->value], $a->media->releaseDate, $a->media->title]
            <=> [$order[$b->relation->value], $b->media->releaseDate, $b->media->title]);

        return $ties;
    }

    private function minVotes(TitleTie $tie): int
    {
        return $tie->media->type === MediaKind::Movie->value ? self::UNIVERSE_MIN_FILM_VOTES : self::UNIVERSE_MIN_VOTES;
    }

    /** The later release continues the earlier one; without both dates, the source's word stands. */
    private function direction(MediaItem $title, TitleTie $tie): TitleRelation
    {
        $own = $title->release_date?->toDateString();
        $other = $tie->media->releaseDate;

        if ($own === null || $other === null || $own === $other) {
            return $tie->relation;
        }

        return $other > $own ? TitleRelation::Next : TitleRelation::Previous;
    }
}

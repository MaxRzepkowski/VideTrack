<?php

namespace App\Catalog\Relations;

use App\Catalog\MediaKind;

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
 *  - Across media, the work a title is based on is always shown; what was
 *    made of it or of its source only when known widely enough, since one
 *    novel or comic may have dozens of minor games and tie-in books. Where
 *    the source files a work both as this title's source and as made of it
 *    (an illustrated edition of a novel beside the novel), it is the source.
 */
final readonly class TieRules
{
    /** Which word about a pair stands when the source has several; the lowest wins. */
    private const CLOSENESS = [
        TitleRelation::SpinOff->value => 0,
        TitleRelation::SpinOffOf->value => 0,
        TitleRelation::BasedOn->value => 1,
        TitleRelation::Adaptation->value => 2,
        TitleRelation::SameSource->value => 3,
        TitleRelation::SameUniverse->value => 4,
        TitleRelation::Next->value => 5,
        TitleRelation::Previous->value => 5,
    ];

    /**
     * @param  string  $type  the title's type
     * @param  string|null  $releaseDate  when the title came out
     * @param  string|null  $ownCollection  the provider collection the title is a part of
     * @param  list<TitleTie>  $ties  as stored, from this title's side
     * @return list<TitleTie> each other title once; the named ties first, then the franchise by release
     */
    public function settle(string $type, ?string $releaseDate, ?string $ownCollection, array $ties): array
    {
        $settled = [];

        foreach ($ties as $tie) {
            $continuation = in_array($tie->relation, [TitleRelation::Next, TitleRelation::Previous], true);

            if ($continuation && $type === MediaKind::Movie->value && $tie->media->type === MediaKind::Movie->value) {
                continue;
            }

            if ($tie->relation === TitleRelation::SameUniverse && $ownCollection !== null && $tie->collectionRef === $ownCollection) {
                continue;
            }

            // No count means the title is stored here: the user's own, whatever its reach.
            if ($this->mustBeKnown($tie->relation) && $tie->media->votes !== null && $tie->media->votes < $this->minVotes($tie)) {
                continue;
            }

            if ($continuation) {
                $tie = new TitleTie($this->direction($releaseDate, $tie), $tie->media, $tie->collectionRef);
            }

            $this->keepClosest($settled, $tie);
        }

        return self::inPageOrder(array_values($settled));
    }

    /**
     * The ties a title's own provider names with its details (a manga's
     * sequel): nothing to second-guess about an outside source, so only the
     * rules every page keeps — each other title once, by the closest thing
     * known of it — in page order.
     *
     * @param  list<TitleTie>  $ties
     * @return list<TitleTie>
     */
    public function fromProvider(array $ties): array
    {
        $kept = [];

        foreach ($ties as $tie) {
            $this->keepClosest($kept, $tie);
        }

        return self::inPageOrder(array_values($kept));
    }

    /**
     * @param  array<string, TitleTie>  $kept  by the other title's key
     */
    private function keepClosest(array &$kept, TitleTie $tie): void
    {
        $key = $tie->media->key();

        if (! isset($kept[$key]) || self::CLOSENESS[$tie->relation->value] < self::CLOSENESS[$kept[$key]->relation->value]) {
            $kept[$key] = $tie;
        }
    }

    /**
     * The order a page lists ties in: by how the other title stands to this
     * one, as TitleRelation lists them (spin-offs, the original, what follows
     * it, what it follows, the rest of the universe, then what it is based on,
     * what was made of it and what shares its source), each kind by release.
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

    /** Whether a title tied this way is shown only when known widely enough. */
    private function mustBeKnown(TitleRelation $relation): bool
    {
        return in_array($relation, [TitleRelation::SameUniverse, TitleRelation::Adaptation, TitleRelation::SameSource], true);
    }

    private function minVotes(TitleTie $tie): int
    {
        $key = "tracker.widely_known_votes.{$tie->media->type}";

        return config()->has($key) ? config()->integer($key) : 0;
    }

    /** The later release continues the earlier one; without both dates, the source's word stands. */
    private function direction(?string $own, TitleTie $tie): TitleRelation
    {
        $other = $tie->media->releaseDate;

        if ($own === null || $other === null || $own === $other) {
            return $tie->relation;
        }

        return $other > $own ? TitleRelation::Next : TitleRelation::Previous;
    }
}

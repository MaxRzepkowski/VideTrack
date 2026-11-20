<?php

namespace App\Catalog;

use App\Support\Payload;

/**
 * What the card of a game's release of its own (its DLC, a remake) says
 * beyond its title: what kind of release it is and whose. Read wherever the
 * card is told of, so a feed, a notification and its switch say the same.
 */
final readonly class RelatedReleaseCard
{
    private function __construct(
        public GameKind $badge,
        /** The game it belongs to, by name. */
        public string $game,
    ) {}

    /**
     * The card's, when it is one; null for a title tracked in its own right.
     *
     * @param  array<array-key, mixed>  $extra  the card's `extra`
     */
    public static function of(array $extra): ?self
    {
        $badge = Payload::enum($extra, 'gameType', GameKind::class);
        $game = Payload::string($extra, 'relatedTo');

        return $badge === null || $game === null ? null : new self($badge, $game);
    }

    /** The switch it answers to; null for a badge no switch covers. */
    public function kind(): ?RelatedReleaseKind
    {
        return RelatedReleaseKind::of($this->badge);
    }

    /**
     * What it is and whose, each its own fact.
     *
     * @return list<string>
     */
    public function facts(?string $locale = null): array
    {
        return [$this->badge->label($locale), $this->game];
    }
}

<?php

namespace App\Catalog\Relations;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * How another title stands to this one, where the provider's own grouping
 * (a film collection) does not say. Named from this title's side: the other
 * title IS this title's spin-off, or what comes next after it.
 */
#[TypeScript]
enum TitleRelation: string
{
    case SpinOff = 'spin_off';

    /** The title this one was spun off from. */
    case SpinOffOf = 'spin_off_of';

    /** What follows this title in its story or series. */
    case Next = 'next';

    /** What this title follows. */
    case Previous = 'previous';

    /** Another film or show of the same franchise, with nothing closer known. */
    case SameUniverse = 'same_universe';

    /** The work in another medium this title is based on: the novel behind a film. */
    case BasedOn = 'based_on';

    /** A work in another medium based on this title: the anime of a manga. */
    case Adaptation = 'adaptation';

    /** A work in another medium based on what this title is based on: the anime beside the manga of one game. */
    case SameSource = 'same_source';

    /** The same tie named from the other title's side. */
    public function inverse(): self
    {
        return match ($this) {
            self::SpinOff => self::SpinOffOf,
            self::SpinOffOf => self::SpinOff,
            self::Next => self::Previous,
            self::Previous => self::Next,
            self::SameUniverse => self::SameUniverse,
            self::BasedOn => self::Adaptation,
            self::Adaptation => self::BasedOn,
            self::SameSource => self::SameSource,
        };
    }

    /** Whether the tie runs to another medium, which its own section on a page lists. */
    public function acrossMedia(): bool
    {
        return in_array($this, [self::BasedOn, self::Adaptation, self::SameSource], true);
    }

    /**
     * What the other title's card says, in the request's language; nothing
     * for a title that merely shares the universe, which the section's
     * heading already says.
     */
    public function label(): ?string
    {
        if ($this === self::SameUniverse) {
            return null;
        }

        return match ($this) {
            self::SpinOff => __('media.relation_spin_off'),
            self::SpinOffOf => __('media.relation_spin_off_of'),
            self::Next => __('media.relation_next'),
            self::Previous => __('media.relation_previous'),
            self::BasedOn => __('media.relation_based_on'),
            self::Adaptation => __('media.relation_adaptation'),
            self::SameSource => __('media.relation_same_source'),
        };
    }
}

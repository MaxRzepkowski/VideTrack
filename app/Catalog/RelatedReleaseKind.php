<?php

namespace App\Catalog;

/**
 * The kinds of a game's releases of its own a tracking surface tells apart:
 * what it adds to the game, and the game made again from scratch or brought
 * to newer machines.
 */
enum RelatedReleaseKind: string
{
    case Addons = 'addons';
    case Remakes = 'remakes';
    case Remasters = 'remasters';

    /** The kind a release badged this way is; null for one that is not a release of its own of a game. */
    public static function of(GameKind $kind): ?self
    {
        return match ($kind) {
            GameKind::Dlc, GameKind::Expansion, GameKind::StandaloneExpansion => self::Addons,
            GameKind::Remake => self::Remakes,
            GameKind::Remaster => self::Remasters,
            default => null,
        };
    }
}

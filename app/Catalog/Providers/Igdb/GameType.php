<?php

namespace App\Catalog\Providers\Igdb;

use App\Catalog\CatalogueShelf;
use App\Catalog\GameKind;

/**
 * IGDB's `game_type`: what kind of release a game entry is. The codes are
 * IGDB's own (api-docs.igdb.com, Game Type).
 */
enum GameType: int
{
    case Main = 0;
    case Dlc = 1;
    case Expansion = 2;
    case Bundle = 3;
    case StandaloneExpansion = 4;
    case Mod = 5;
    case Episode = 6;
    case Season = 7;
    case Remake = 8;
    case Remaster = 9;
    case Expanded = 10;
    case Port = 11;
    case Fork = 12;
    case Pack = 13;
    case Update = 14;

    /**
     * Standalone releases worth tracking on their own: bundles, mods,
     * updates, ports and forks shadow their parent under the same name.
     *
     * @return list<self>
     */
    public static function trackable(): array
    {
        return [self::Main, self::Dlc, self::Expansion, self::StandaloneExpansion, self::Remake, self::Remaster, self::Expanded];
    }

    /**
     * What search finds: the trackable kinds, plus mods and bundles —
     * standalone total conversions like "Kroniki Myrtany: Archolos" and
     * compilations like "Crash Bandicoot N. Sane Trilogy" are tracked in their
     * own right, though Discover keeps them out of its listings.
     *
     * @return list<self>
     */
    public static function searchable(): array
    {
        return [...self::trackable(), self::Bundle, self::Mod];
    }

    /**
     * The parts of a line of games — a series or a franchise read as one:
     * the games, their remakes, remasters and standalone expansions. DLC
     * belongs to its game's page; a bundle or a port repeats a part.
     *
     * @return list<self>
     */
    public static function lineParts(): array
    {
        return [self::Main, self::StandaloneExpansion, self::Remake, self::Remaster];
    }

    /**
     * The parts of one series: its games, their remakes (a new game) and
     * standalone expansions. A remaster stays off, as DLC does: it is its
     * game again on other machines, and in a long series it buries the line.
     *
     * @return list<self>
     */
    public static function seriesParts(): array
    {
        return [self::Main, self::StandaloneExpansion, self::Remake];
    }

    /**
     * The APICalypse clause matching these kinds.
     *
     * @param  list<self>  $types
     */
    public static function clause(array $types): string
    {
        $codes = array_map(fn (self $type): int => $type->value, $types);
        sort($codes);

        return 'game_type = ('.implode(',', $codes).')';
    }

    /** The badge a card wears (the UI words it); a main game has none. */
    public function kind(): ?GameKind
    {
        return match ($this) {
            self::Main => null,
            self::Dlc => GameKind::Dlc,
            self::Expansion => GameKind::Expansion,
            self::Bundle => GameKind::Bundle,
            self::StandaloneExpansion => GameKind::StandaloneExpansion,
            self::Mod => GameKind::Mod,
            self::Episode => GameKind::Episode,
            self::Season => GameKind::Season,
            self::Remake => GameKind::Remake,
            self::Remaster => GameKind::Remaster,
            self::Expanded => GameKind::Expanded,
            self::Port => GameKind::Port,
            self::Fork => GameKind::Fork,
            self::Pack => GameKind::Pack,
            self::Update => GameKind::Update,
        };
    }

    /**
     * The shelf of a company page it goes on: standalone games, add-ons, or
     * everything else.
     */
    public function shelf(): CatalogueShelf
    {
        return match ($this) {
            self::Dlc, self::Expansion => CatalogueShelf::Dlc,
            self::Bundle, self::Mod, self::Episode, self::Season, self::Port, self::Fork, self::Pack, self::Update => CatalogueShelf::Other,
            self::Main, self::StandaloneExpansion, self::Remake, self::Remaster, self::Expanded => CatalogueShelf::Game,
        };
    }
}

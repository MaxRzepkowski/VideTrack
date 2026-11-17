<?php

namespace App\Catalog;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** What an episode opens or closes in its show: the chip its card carries. */
#[TypeScript]
enum EpisodeBadge: string
{
    case SeriesPremiere = 'series_premiere';
    case SeasonPremiere = 'season_premiere';
    case MidSeasonFinale = 'mid_season_finale';
    case SeriesFinale = 'series_finale';
    case SeasonFinale = 'season_finale';

    /**
     * The badge from what a provider tells about one episode: the episode
     * opening a numbered season premieres it (specials never do, the first
     * season premieres the series), and the provider's episode type names a
     * break or a finale, the series finale when the show ended with it.
     */
    public static function of(?EpisodeType $type, int $seasonNumber, bool $opensSeason, bool $closesEndedShow): ?self
    {
        return match (true) {
            $opensSeason && $seasonNumber === 1 => self::SeriesPremiere,
            $opensSeason && $seasonNumber !== 0 => self::SeasonPremiere,
            $type === EpisodeType::MidSeason => self::MidSeasonFinale,
            $type === EpisodeType::Finale && $closesEndedShow => self::SeriesFinale,
            $type === EpisodeType::Finale => self::SeasonFinale,
            default => null,
        };
    }

    /** The episode opens its season; the first season's opener premieres the series too. */
    public function isPremiere(): bool
    {
        return $this === self::SeriesPremiere || $this === self::SeasonPremiere;
    }

    /** The episode closes a run of the show: a season, its first half, or the series. */
    public function isFinale(): bool
    {
        return $this === self::MidSeasonFinale || $this === self::SeasonFinale || $this === self::SeriesFinale;
    }

    public function label(?string $locale = null): string
    {
        return match ($this) {
            self::SeriesPremiere => __('common.series_premiere', [], $locale),
            self::SeasonPremiere => __('common.season_premiere', [], $locale),
            self::MidSeasonFinale => __('common.mid_season_finale', [], $locale),
            self::SeasonFinale => __('common.season_finale', [], $locale),
            self::SeriesFinale => __('common.series_finale', [], $locale),
        };
    }
}

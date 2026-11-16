<?php

use App\Catalog\EpisodeBadge;
use App\Catalog\EpisodeType;

test('the episode opening a numbered season premieres it, the first season the series', function () {
    expect(EpisodeBadge::of(EpisodeType::Standard, 1, opensSeason: true, closesEndedShow: false))->toBe(EpisodeBadge::SeriesPremiere)
        ->and(EpisodeBadge::of(null, 3, opensSeason: true, closesEndedShow: false))->toBe(EpisodeBadge::SeasonPremiere)
        ->and(EpisodeBadge::of(EpisodeType::Standard, 0, opensSeason: true, closesEndedShow: false))->toBeNull();
});

test('the provider names a break or a finale, the series finale when the show ended with it', function () {
    expect(EpisodeBadge::of(EpisodeType::MidSeason, 2, opensSeason: false, closesEndedShow: false))->toBe(EpisodeBadge::MidSeasonFinale)
        ->and(EpisodeBadge::of(EpisodeType::Finale, 2, opensSeason: false, closesEndedShow: false))->toBe(EpisodeBadge::SeasonFinale)
        ->and(EpisodeBadge::of(EpisodeType::Finale, 2, opensSeason: false, closesEndedShow: true))->toBe(EpisodeBadge::SeriesFinale)
        ->and(EpisodeBadge::of(EpisodeType::Standard, 2, opensSeason: false, closesEndedShow: true))->toBeNull();
});

test('a premiere of the series or of a season opens its season, a finale does not', function () {
    $premieres = array_column(array_map(fn (EpisodeBadge $badge): array => [$badge->value, $badge->isPremiere()], EpisodeBadge::cases()), 1, 0);

    expect($premieres)->toBe([
        'series_premiere' => true,
        'season_premiere' => true,
        'mid_season_finale' => false,
        'series_finale' => false,
        'season_finale' => false,
    ]);
});

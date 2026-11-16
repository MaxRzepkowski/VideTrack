<?php

namespace App\Integrations;

/**
 * How a report from elsewhere (an import's summary, a delivery log) names an
 * episode: its show and its place in it. The show alone would read as the
 * same line for every episode of a binge.
 */
final class EpisodeLabel
{
    public static function of(string $show, ?int $season, ?int $number): string
    {
        return $season === null || $number === null ? $show : sprintf('%s S%02dE%02d', $show, $season, $number);
    }
}

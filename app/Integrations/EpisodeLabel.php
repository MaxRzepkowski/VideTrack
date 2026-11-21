<?php

namespace App\Integrations;

use App\Catalog\EpisodeCode;

/**
 * How a report from elsewhere (an import's summary, a delivery log) names an
 * episode: its show and its place in it. The show alone would read as the
 * same line for every episode of a binge.
 */
final class EpisodeLabel
{
    public static function of(string $show, ?int $season, ?int $number): string
    {
        return $season === null || $number === null ? $show : $show.' '.EpisodeCode::of($season, $number);
    }

    /** An episode a source names by its own title only: its place first, then the title. */
    public static function untitledShow(int $season, int $number, ?string $title): string
    {
        $code = EpisodeCode::of($season, $number);

        return $title === null ? $code : "{$code} {$title}";
    }
}

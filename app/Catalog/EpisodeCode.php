<?php

namespace App\Catalog;

/** How an episode's place in its show is written: "S02E05". */
final class EpisodeCode
{
    public static function of(int $season, int $number): string
    {
        return sprintf('S%02dE%02d', $season, $number);
    }
}

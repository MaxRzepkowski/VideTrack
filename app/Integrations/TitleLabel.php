<?php

namespace App\Integrations;

/**
 * How a report from elsewhere names a movie or a show: its title, with its
 * year when the source gives one, so two titles of one name read apart.
 */
final class TitleLabel
{
    public static function of(string $title, ?int $year): string
    {
        return $year === null ? $title : "{$title} ({$year})";
    }
}

<?php

namespace App\Integrations\Import\Rows;

use App\Library\PlayDating;
use Carbon\CarbonImmutable;

/**
 * Episodes $first..$last of one season, watched, when the source knows that
 * they were but not when each was — an anime tracker's "12 of 12". Every
 * episode gets the same date; the show is named by its own ids, and the
 * provider descends by season and number.
 */
final readonly class EpisodeRunRow implements ImportRow
{
    /**
     * @param  array<string, string>  $seriesExternalIds
     */
    public function __construct(
        public string $label,
        public array $seriesExternalIds,
        public int $season,
        public int $first,
        public int $last,
        public ?CarbonImmutable $at = null,
        public PlayDating $dating = PlayDating::Viewing,
    ) {}
}

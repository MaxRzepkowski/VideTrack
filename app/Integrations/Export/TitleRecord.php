<?php

namespace App\Integrations\Export;

/**
 * One title the user rated or watched, flattened for another service's
 * import format: those match a title by an id of theirs or by title and
 * year, and hold one rating and one watch date per title, not a history.
 */
final readonly class TitleRecord
{
    public function __construct(
        public string $type,
        public string $title,
        public ?int $year,
        public string $provider,
        public string $ref,
        public ?string $imdbId,
        public ?int $rating,
        /** The day the rating was last given (Y-m-d, on the user's calendar) — not the watch date. */
        public ?string $ratedOn,
        /** The newest attempt's day, the same way. */
        public ?string $watchedOn,
    ) {}
}

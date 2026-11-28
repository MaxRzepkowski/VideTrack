<?php

namespace App\Integrations\Import\Data;

use App\Enums\PlayOutcome;
use App\Library\MutationSource;
use App\Library\PlayDating;
use App\Library\RecordKind;
use Carbon\CarbonImmutable;

/**
 * One closed attempt. Without a date the play lands on the title's release
 * date, else now — MarkWatched's "release date" mode. $amount is the cost
 * in the type's progress unit. $cycle is the run as the source numbers it
 * (1 the first, 2 and up a rewatch); null places the play in the run under
 * way on its date.
 *
 * $record says what the entry comes from, and so what a completed viewing
 * is weighed against: one another source already logged a few hours off
 * stands for it ({@see RecordKind}).
 *
 * $dating says what $at is: the viewing's moment, or only the source's last
 * activity on the title — which moves from one export to the next.
 *
 * $playedSeconds is for a source that knows how long a playback ran but
 * not whether it finished (a media server's playback log): the play stands
 * only when it covers the completion threshold of the title's runtime,
 * which the catalog knows once the title is placed.
 */
final readonly class ImportPlay
{
    public function __construct(
        public ?CarbonImmutable $at = null,
        public PlayOutcome $outcome = PlayOutcome::Completed,
        public ?int $amount = null,
        public ?int $cycle = null,
        public PlayDating $dating = PlayDating::Viewing,
        public ?int $playedSeconds = null,
        /** Where the entry first came in, for a history that kept it (a backup); null for the import itself. */
        public ?MutationSource $source = null,
        public RecordKind $record = RecordKind::History,
    ) {}
}

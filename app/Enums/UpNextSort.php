<?php

namespace App\Enums;

use App\Library\Data\UpNextOrderKeys;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * How the dashboard ranks the titles the user is in the middle of — Up Next
 * to Watch and its flat-type counterparts (Currently Playing / Reading). One
 * preference covers every section: the sections differ in what they hold, not
 * in the order the user wants to see it in.
 */
#[TypeScript]
enum UpNextSort: string
{
    /** Most recently touched first. */
    case Activity = 'activity';

    /**
     * Closest to being finished first: shows count the runtime left in their
     * unwatched aired episodes, flat types the unfinished share of the title
     * (a game against its time-to-beat, a book against its page count).
     */
    case Remaining = 'remaining';

    /** Freshest release first — the waiting episode's air date, or the title's own. */
    case Released = 'released';

    /** Title, A→Z. */
    case Title = 'title';

    /**
     * Every order but the default one falls back to the most recent activity
     * on a tie, so entries that compare equal keep a stable and meaningful
     * order instead of a database one.
     */
    public function compare(UpNextOrderKeys $a, UpNextOrderKeys $b): int
    {
        $order = match ($this) {
            self::Activity => 0,
            self::Remaining => match (true) {
                $a->remaining === null && $b->remaining === null => 0,
                $a->remaining === null => 1,
                $b->remaining === null => -1,
                default => $a->remaining <=> $b->remaining,
            },
            self::Released => strcmp((string) $b->released, (string) $a->released),
            self::Title => strnatcasecmp($a->title, $b->title),
        };

        return $order !== 0 ? $order : strcmp((string) $b->activity, (string) $a->activity);
    }
}

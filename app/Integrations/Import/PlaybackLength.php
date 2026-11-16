<?php

namespace App\Integrations\Import;

use App\Integrations\Import\Rows\SkipRow;
use App\Library\PlaybackCompletion;

/**
 * Whether a playback a source could only time ({@see Data\ImportPlay::$playedSeconds})
 * stands as a viewing of the title it was placed on. A title whose runtime
 * nobody knows cannot tell, and says so rather than guessing.
 */
final readonly class PlaybackLength
{
    /** Why the playback is no viewing; null when it is one. */
    public function shortfall(string $label, ?int $runtime, int $seconds): ?SkipRow
    {
        if ($runtime === null || $runtime <= 0) {
            return new SkipRow('import.skip_play_runtime_unknown', ['label' => $label]);
        }

        if (PlaybackCompletion::covers($seconds, $runtime)) {
            return null;
        }

        return new SkipRow('import.skip_play_too_short', [
            'label' => $label,
            'share' => (int) floor(PlaybackCompletion::share($seconds, $runtime)),
            'threshold' => PlaybackCompletion::threshold(),
        ]);
    }
}

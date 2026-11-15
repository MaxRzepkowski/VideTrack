<?php

namespace App\Tasks;

use App\Models\TaskRun;
use Closure;

/**
 * Live progress of a long run: its summary is rewritten in place as it goes,
 * at most once per interval — a single item can spend seconds at a provider,
 * and the Tasks page asks while a run is active, so an operator sees it
 * moving instead of a bare "Started" for minutes.
 */
final class ProgressReporter
{
    /** Seconds between two writes of the summary. */
    private const INTERVAL_SECONDS = 2.0;

    private float $reportedAt = 0.0;

    public function __construct(private readonly TaskRun $run) {}

    /**
     * @param  Closure(): string  $summary  built only when it is written
     */
    public function report(Closure $summary): void
    {
        // The app's clock, which tests can travel.
        $now = (float) now()->getPreciseTimestamp(3) / 1000;

        if ($now - $this->reportedAt < self::INTERVAL_SECONDS) {
            return;
        }

        $this->reportNow($summary());
    }

    /**
     * Writes at once, for a step that may take long: what the page shows is
     * then what is happening, not what happened up to two seconds before.
     */
    public function reportNow(string $summary): void
    {
        $this->reportedAt = (float) now()->getPreciseTimestamp(3) / 1000;
        $this->run->update(['summary' => $summary]);
    }
}

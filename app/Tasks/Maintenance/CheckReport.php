<?php

namespace App\Tasks\Maintenance;

use App\Tasks\Data\TaskLine;
use App\Tasks\Maintenance\Data\CheckLine;

/**
 * What a check tells about its run. Its lines are what it sees now, shown in
 * its row until the next run; the lines of the run before are at hand, so a
 * check can tell what changed. A note is something that happened and is
 * worth the task history (a change, a clean-up done); a run without notes
 * leaves no history behind.
 */
final class CheckReport
{
    /** @var list<CheckLine> */
    private array $lines = [];

    /** @var list<TaskLine> */
    private array $notes = [];

    /**
     * @param  list<CheckLine>  $previous  what the run before said
     */
    public function __construct(
        private readonly array $previous = [],
        public readonly RunCause $cause = RunCause::Window,
    ) {}

    /** What the run before said about $subject, if it said anything. */
    public function previous(string $subject): ?CheckLine
    {
        foreach ($this->previous as $line) {
            if ($line->subject === $subject) {
                return $line;
            }
        }

        return null;
    }

    public function add(CheckLine $line): void
    {
        $this->lines[] = $line;
    }

    /**
     * @param  array<string, string|int>  $params
     */
    public function note(string $key, array $params = []): void
    {
        $this->notes[] = new TaskLine($key, $params);
    }

    /**
     * @return list<CheckLine>
     */
    public function lines(): array
    {
        return $this->lines;
    }

    /**
     * @return list<TaskLine>
     */
    public function notes(): array
    {
        return $this->notes;
    }
}

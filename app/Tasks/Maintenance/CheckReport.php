<?php

namespace App\Tasks\Maintenance;

use App\Tasks\Data\TaskLine;

/**
 * What a check tells about its run. The result is what it sees now, shown in
 * its row until the next run; a note is something that happened and is worth
 * the task history (a change, a clean-up done). A run without notes leaves no
 * history behind.
 */
final class CheckReport
{
    /** @var list<TaskLine> */
    private array $results = [];

    /** @var list<TaskLine> */
    private array $notes = [];

    /**
     * @param  array<string, string|int>  $params
     */
    public function result(string $key, array $params = []): void
    {
        $this->results[] = new TaskLine($key, $params);
    }

    /**
     * @param  array<string, string|int>  $params
     */
    public function note(string $key, array $params = []): void
    {
        $this->notes[] = new TaskLine($key, $params);
    }

    /**
     * @return list<TaskLine>
     */
    public function results(): array
    {
        return $this->results;
    }

    /**
     * @return list<TaskLine>
     */
    public function notes(): array
    {
        return $this->notes;
    }
}

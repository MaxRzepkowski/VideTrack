<?php

namespace App\Jobs;

use App\Actions\Media\SyncCreatorTitles;
use App\Catalog\Creators\Creators;
use App\Catalog\Creators\CreatorTable;
use App\Jobs\Concerns\RunsAsTask;
use App\Models\TaskRun;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;

/**
 * The daily sweep over every followed person, or every followed company and
 * network: each goes through SyncCreatorTitles. Also the retry channel for
 * the one-shot sync right after following someone.
 */
class RefreshFollowedCreators implements ShouldQueue
{
    use Dispatchable, Queueable, RunsAsTask;

    public int $timeout = 1800;

    public int $maxExceptions = self::BREAKS_ALLOWED;

    public function __construct(
        private readonly TaskRun $taskRun,
        private readonly CreatorTable $table,
    ) {}

    public static function queueTask(CreatorTable $table): void
    {
        self::dispatch(TaskRun::queueTask($table->sweepTask()->value), $table);
    }

    public function handle(Creators $creators, SyncCreatorTitles $sync): void
    {
        $this->taskRun->markStarted();

        $checked = 0;
        $created = 0;
        $removed = 0;
        $errors = [];

        foreach ($creators->followed($this->table) as $creator) {
            $result = $sync->execute($creator);

            $created += $result->created;
            $removed += $result->removed;
            $errors = [...$errors, ...$result->errors];
            $checked++;
        }

        $this->taskRun->markSuccess(
            "Checked {$checked} followed {$this->table->value}; auto-followed {$created} upcoming titles, withdrew {$removed} with vanished credits.",
            $errors,
        );
    }
}

<?php

namespace App\Models;

use App\Enums\TaskRunStatus;
use App\Tasks\TaskName;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * One run of a background task. Created as `pending` when the
 * job is queued; the job marks it started and finally success/failure with a
 * human-readable summary and error details.
 *
 * @property int $id
 * @property int|null $user_id
 * @property string $name
 * @property TaskRunStatus $status
 * @property string|null $summary
 * @property list<string>|null $errors
 * @property list<string>|null $details what the run noted without anything going wrong
 * @property Carbon $queued_at
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 */
class TaskRun extends Model
{
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => TaskRunStatus::class,
            'errors' => 'array',
            'details' => 'array',
            'queued_at' => 'datetime',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /**
     * The newest run of each named scheduled task (system runs, no user's).
     *
     * @param  Builder<TaskRun>  $query
     * @param  list<string>  $names
     */
    public function scopeLatestSystemRunOfEach(Builder $query, array $names): void
    {
        $query->whereIn('id', self::query()
            ->selectRaw('max(id)')
            ->whereNull('user_id')
            ->whereIn('name', $names)
            ->groupBy('name'));
    }

    /**
     * A system run of the task queued or under way.
     *
     * @param  Builder<TaskRun>  $query
     */
    public function scopeInFlight(Builder $query, TaskName $task): void
    {
        $query->whereNull('user_id')
            ->where('name', $task->value)
            ->whereIn('status', [TaskRunStatus::Pending, TaskRunStatus::Started]);
    }

    public static function queueTask(string $name, ?int $userId = null): self
    {
        return self::query()->create([
            'user_id' => $userId,
            'name' => $name,
            'status' => TaskRunStatus::Pending,
            'queued_at' => now(),
        ]);
    }

    /** Started again by a retry is still started; a finished run never starts again. */
    public function markStarted(): void
    {
        $this->assertOpen();
        $this->update(['status' => TaskRunStatus::Started, 'started_at' => now()]);
    }

    /**
     * @param  list<string>  $errors  what went wrong along the way
     * @param  list<string>  $details  what the run noted without anything going wrong
     */
    public function markSuccess(string $summary, array $errors = [], array $details = []): void
    {
        $this->finish(TaskRunStatus::Success, $summary, $errors, $details);
    }

    /**
     * @param  list<string>  $errors
     * @param  list<string>  $details  what the run noted before it broke
     */
    public function markFailure(string $summary, array $errors = [], array $details = []): void
    {
        $this->finish(TaskRunStatus::Failure, $summary, $errors, $details);
    }

    /** A run ends once: a second ending would rewrite what the first reported. */
    private function assertOpen(): void
    {
        if ($this->status->isFinished()) {
            throw new LogicException("Task run [{$this->id}] already finished as {$this->status->value}.");
        }
    }

    /**
     * A job's last word when it fails: a run that already reported how it
     * ended keeps that ending.
     */
    public function markFailureIfOpen(string $summary): void
    {
        if (! $this->status->isFinished()) {
            $this->finish(TaskRunStatus::Failure, $summary, [], []);
        }
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @param  list<string>  $errors
     * @param  list<string>  $details
     */
    private function finish(TaskRunStatus $status, string $summary, array $errors, array $details): void
    {
        $this->assertOpen();
        $this->update([
            'status' => $status,
            'summary' => $summary,
            'errors' => $errors === [] ? null : $errors,
            'details' => $details === [] ? null : $details,
            'finished_at' => now(),
        ]);
    }
}

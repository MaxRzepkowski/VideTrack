<?php

namespace App\Models;

use App\Enums\TaskRunStatus;
use Database\Factories\MaintenanceCheckStateFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Where one maintenance check stands: queued, at work, or how its last run
 * ended. Overwritten by every run; the history keeps only the runs that had
 * something to say.
 *
 * @property int $id
 * @property string $code
 * @property TaskRunStatus $status
 * @property string|null $run_id the run that queued or took the check last
 * @property list<mixed>|null $result the lines the last run saw, as kept
 * @property array<string, mixed>|null $error why the last run broke, as kept
 * @property Carbon|null $started_at
 * @property Carbon|null $checked_at
 */
class MaintenanceCheckState extends Model
{
    /** @use HasFactory<MaintenanceCheckStateFactory> */
    use HasFactory;

    protected $table = 'maintenance_checks';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => TaskRunStatus::class,
            'result' => 'array',
            'error' => 'array',
            'started_at' => 'datetime',
            'checked_at' => 'datetime',
        ];
    }

    /** Queued or at work: another run would only wait behind it. */
    public function isInFlight(): bool
    {
        return ! $this->status->isFinished();
    }
}

<?php

namespace App\Tasks\Liveness;

use App\Support\Payload;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

/**
 * The last sign of life of each background process, written by the process
 * itself: the scheduler on its tick, the worker on every loop and job. A
 * process that left no fresh sign is not running; no other process is asked.
 */
final class Heartbeat
{
    /** One process writes its sign at most this often: the worker loops every few seconds. */
    public const REWRITE_AFTER_SECONDS = 60;

    /** @var array<string, CarbonImmutable> */
    private array $written = [];

    public function beat(BeatingProcess $process): void
    {
        $now = now()->toImmutable();
        $written = $this->written[$process->value] ?? null;

        if ($written !== null && $written->diffInSeconds($now) < self::REWRITE_AFTER_SECONDS) {
            return;
        }

        $this->written[$process->value] = $now;
        Cache::forever(self::key($process), $now->toIso8601String());
    }

    /** The process's last sign of life; none for one that has never run here. */
    public function lastBeat(BeatingProcess $process): ?CarbonImmutable
    {
        return Payload::instant(Cache::get(self::key($process)));
    }

    private static function key(BeatingProcess $process): string
    {
        return "heartbeat:{$process->value}";
    }
}

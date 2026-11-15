<?php

namespace App\Integrations\VideTrack\Backup;

use App\Support\Payload;
use Carbon\CarbonImmutable;

/**
 * A later run through a show — its number and when it began. It travels on
 * its own because a run can begin before its first viewing, or stay empty
 * when nothing of it has been watched yet. Run 1 is implicit and never
 * written.
 */
final readonly class BackupCycle implements BackupEntry
{
    public function __construct(
        public BackupPosition $position,
        public int $number,
        public CarbonImmutable $startedAt,
    ) {}

    public function toArray(): array
    {
        return [
            ...$this->position->toArray(),
            'number' => $this->number,
            'started_at' => $this->startedAt->toIso8601String(),
        ];
    }

    /**
     * @param  array<mixed>  $entry
     */
    public static function fromArray(array $entry): self
    {
        $position = BackupPosition::fromArray($entry);
        $number = Payload::positiveInt($entry, 'number');
        $startedAt = Payload::instant($entry['started_at'] ?? null);

        if ($number === null || $number < 2 || $startedAt === null) {
            throw InvalidBackupEntry::because('import.skip_backup_cycle_incomplete', $entry);
        }

        return new self($position, $number, $startedAt);
    }
}

<?php

namespace App\Integrations\VideTrack\Backup;

use App\Enums\MediaUserStatus;
use App\Support\Payload;
use Carbon\CarbonImmutable;

/**
 * An open attempt — on hold or in progress — with its counter (pages,
 * chapters, minutes) and when it was set.
 */
final readonly class BackupStatus implements BackupEntry
{
    public function __construct(
        public BackupPosition $position,
        public MediaUserStatus $status,
        public ?CarbonImmutable $setAt,
        public ?int $progress,
    ) {}

    public function toArray(): array
    {
        return [
            ...$this->position->toArray(),
            'status' => $this->status->value,
            'set_at' => $this->setAt?->toIso8601String(),
            'progress' => $this->progress,
        ];
    }

    /**
     * @param  array<mixed>  $entry
     */
    public static function fromArray(array $entry): self
    {
        return new self(
            BackupPosition::fromArray($entry),
            Payload::enum($entry, 'status', MediaUserStatus::class)
                ?? throw InvalidBackupEntry::because('import.skip_backup_unknown_status', $entry),
            Payload::instant($entry['set_at'] ?? null),
            Payload::positiveInt($entry, 'progress'),
        );
    }
}

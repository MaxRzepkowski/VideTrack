<?php

namespace App\Integrations\VideTrack\Backup;

use App\Enums\PlayOutcome;
use App\Support\Payload;
use Carbon\CarbonImmutable;

/**
 * A closed attempt: when, how it ended, what it cost in the type's progress
 * unit (games: minutes, books: pages, manga: chapters) and the run it
 * belongs to. Run 1 is the implicit first one and leaves the key out, so
 * a file without runs reads the same.
 */
final readonly class BackupPlay implements BackupEntry
{
    public function __construct(
        public BackupPosition $position,
        public CarbonImmutable $watchedAt,
        public PlayOutcome $outcome,
        public ?int $amount,
        public int $cycle,
    ) {}

    public function toArray(): array
    {
        return [
            ...$this->position->toArray(),
            'watched_at' => $this->watchedAt->toIso8601String(),
            'outcome' => $this->outcome->value,
            'amount' => $this->amount,
            ...($this->cycle >= 2 ? ['cycle' => $this->cycle] : []),
        ];
    }

    /**
     * @param  array<mixed>  $entry
     */
    public static function fromArray(array $entry): self
    {
        $position = BackupPosition::fromArray($entry);
        $watchedAt = Payload::instant($entry['watched_at'] ?? null)
            ?? throw InvalidBackupEntry::because('import.skip_no_watched_at', $entry);

        return new self(
            $position,
            $watchedAt,
            Payload::enum($entry, 'outcome', PlayOutcome::class)
                ?? throw InvalidBackupEntry::because('import.skip_backup_no_outcome', $entry),
            Payload::positiveInt($entry, 'amount'),
            Payload::positiveInt($entry, 'cycle') ?? 1,
        );
    }
}

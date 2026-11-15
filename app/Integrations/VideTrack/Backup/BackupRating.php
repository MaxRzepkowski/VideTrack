<?php

namespace App\Integrations\VideTrack\Backup;

use App\Support\Payload;
use Carbon\CarbonImmutable;

/**
 * A rating on the 1–10 scale and when it was last given, so the activity
 * of the restored account shows the original day.
 */
final readonly class BackupRating implements BackupEntry
{
    public function __construct(
        public BackupPosition $position,
        public int $rating,
        public ?CarbonImmutable $ratedAt,
    ) {}

    public function toArray(): array
    {
        return [
            ...$this->position->toArray(),
            'rating' => $this->rating,
            'rated_at' => $this->ratedAt?->toIso8601String(),
        ];
    }

    /**
     * @param  array<mixed>  $entry
     */
    public static function fromArray(array $entry): self
    {
        $position = BackupPosition::fromArray($entry);

        if (! is_int($entry['rating'] ?? null)) {
            throw InvalidBackupEntry::because('import.skip_backup_no_rating', $entry);
        }

        return new self($position, $entry['rating'], Payload::instant($entry['rated_at'] ?? null));
    }
}

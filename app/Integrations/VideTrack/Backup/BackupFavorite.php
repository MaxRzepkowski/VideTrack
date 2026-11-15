<?php

namespace App\Integrations\VideTrack\Backup;

use App\Support\Payload;
use Carbon\CarbonImmutable;

/** A favorite and since when. */
final readonly class BackupFavorite implements BackupEntry
{
    public function __construct(
        public BackupPosition $position,
        public ?CarbonImmutable $favoritedAt,
    ) {}

    public function toArray(): array
    {
        return [
            ...$this->position->toArray(),
            'favorited_at' => $this->favoritedAt?->toIso8601String(),
        ];
    }

    /**
     * @param  array<mixed>  $entry
     */
    public static function fromArray(array $entry): self
    {
        return new self(BackupPosition::fromArray($entry), Payload::instant($entry['favorited_at'] ?? null));
    }
}

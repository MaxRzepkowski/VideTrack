<?php

namespace App\Integrations\VideTrack\Backup;

use App\Catalog\Creators\CreatorKind;
use App\Models\ListItem;
use App\Support\Payload;
use Carbon\CarbonImmutable;
use LogicException;

/** One position of a list — a title, a person or a company — and when it was added. */
final readonly class BackupListItem implements BackupEntry
{
    public function __construct(
        public BackupPosition|BackupCreator $target,
        public ?CarbonImmutable $addedAt,
    ) {}

    /** The row with its title, person and company loaded. */
    public static function of(ListItem $row): self
    {
        $target = match (true) {
            $row->person !== null => BackupCreator::of($row->person),
            $row->company !== null => BackupCreator::of($row->company),
            $row->mediaItem !== null => BackupPosition::of($row->mediaItem),
            default => throw new LogicException("List item [{$row->id}] holds nothing."),
        };

        return new self($target, $row->created_at === null ? null : CarbonImmutable::instance($row->created_at));
    }

    public function toArray(): array
    {
        return [
            ...$this->target->toArray(),
            'added_at' => $this->addedAt?->toIso8601String(),
        ];
    }

    /**
     * @param  array<mixed>  $entry
     */
    public static function fromArray(array $entry): self
    {
        $creator = BackupCreator::fromArray($entry);

        if ($creator?->kind === CreatorKind::Collection) {
            throw InvalidBackupEntry::because('import.skip_backup_collection_on_list', $entry);
        }

        return new self($creator ?? BackupPosition::fromArray($entry), Payload::instant($entry['added_at'] ?? null));
    }
}

<?php

namespace App\Integrations\VideTrack\Backup;

use App\Enums\FollowMode;
use App\Models\Follow;
use App\Support\Payload;
use LogicException;

/**
 * A bell the user rang or silenced — on a title, a person, a company or a
 * collection. An auto-follow a followed creator rang is not written: it
 * comes back when the creator's bell does.
 */
final readonly class BackupFollow implements BackupEntry
{
    public function __construct(
        public BackupPosition|BackupCreator $target,
        public FollowMode $mode,
    ) {}

    /** The row with the title or creator it is on loaded. */
    public static function of(Follow $row): self
    {
        $target = match (true) {
            $row->mediaItem !== null => BackupPosition::of($row->mediaItem),
            $row->person !== null => BackupCreator::of($row->person),
            $row->company !== null => BackupCreator::of($row->company),
            $row->list !== null => BackupCreator::of($row->list),
            default => throw new LogicException("Follow [{$row->id}] is on nothing."),
        };

        return new self($target, $row->mode);
    }

    public function toArray(): array
    {
        return [...$this->target->toArray(), 'mode' => $this->mode->value];
    }

    /**
     * @param  array<mixed>  $entry
     */
    public static function fromArray(array $entry): self
    {
        $mode = Payload::enum($entry, 'mode', FollowMode::class)
            ?? throw InvalidBackupEntry::because('import.skip_backup_follow_no_mode', $entry);

        return new self(BackupCreator::fromArray($entry) ?? BackupPosition::fromArray($entry), $mode);
    }
}

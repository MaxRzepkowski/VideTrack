<?php

namespace App\Integrations\VideTrack\Backup;

use App\Integrations\Import\Data\MediaSubject;
use App\Models\MediaItem;

/**
 * Where an entry sits: the provider coordinates the restore persists the
 * position from, so nothing has to be looked up. The title rides along for
 * whoever reads the file; the restore reads the metadata from the provider.
 */
final readonly class BackupPosition
{
    public function __construct(
        public string $type,
        public string $provider,
        public string $ref,
        public string $title,
    ) {}

    public static function of(MediaItem $item): self
    {
        return new self($item->type, $item->provider, $item->provider_ref, $item->title);
    }

    /**
     * @return array{type: string, provider: string, ref: string, title: string}
     */
    public function toArray(): array
    {
        return ['type' => $this->type, 'provider' => $this->provider, 'ref' => $this->ref, 'title' => $this->title];
    }

    /**
     * @param  array<mixed>  $entry
     */
    public static function fromArray(array $entry): self
    {
        $type = $entry['type'] ?? null;
        $provider = $entry['provider'] ?? null;
        $ref = $entry['ref'] ?? null;

        if (! is_string($type) || ! is_string($provider) || ! is_string($ref)) {
            throw InvalidBackupEntry::because('import.skip_backup_no_position', $entry);
        }

        return new self($type, $provider, $ref, is_string($entry['title'] ?? null) ? $entry['title'] : "{$type}:{$ref}");
    }

    public function subject(): MediaSubject
    {
        return MediaSubject::direct($this->type, $this->provider, $this->ref, $this->title);
    }
}

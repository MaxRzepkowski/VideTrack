<?php

namespace App\Integrations\VideTrack\Backup;

use App\Catalog\Creators\CreatorKind;
use App\Catalog\Creators\CreatorRef;
use App\Models\Company;
use App\Models\MediaList;
use App\Models\Person;
use LogicException;

/**
 * A creator an entry is about — a person, a studio or network, a provider
 * collection — by its coordinates at the provider and the name it went by.
 * It sits outside the media type registry, so it restores from the file
 * alone. A company keeps its kind: a studio and a network can share a ref.
 */
final readonly class BackupCreator
{
    private const COMPANY = 'company';

    public function __construct(
        public CreatorKind $kind,
        public string $provider,
        public string $ref,
        public string $name,
    ) {}

    public static function of(Person|Company|MediaList $row): self
    {
        if ($row instanceof MediaList) {
            $source = $row->collectionSource() ?? throw new LogicException("List [{$row->id}] is not a collection.");

            return new self(CreatorKind::Collection, $source['provider'], $source['ref'], $row->displayTitle());
        }

        return $row instanceof Person
            ? new self(CreatorKind::Person, $row->provider, $row->provider_ref, $row->name)
            : new self(CreatorKind::from($row->kind), $row->provider, $row->provider_ref, $row->name);
    }

    public function ref(): CreatorRef
    {
        return new CreatorRef($this->kind, $this->provider, $this->ref);
    }

    /**
     * @return array<string, string>
     */
    public function toArray(): array
    {
        return match ($this->kind) {
            CreatorKind::Company, CreatorKind::Network => [
                'type' => self::COMPANY,
                'kind' => $this->kind->value,
                'provider' => $this->provider,
                'ref' => $this->ref,
                'title' => $this->name,
            ],
            default => ['type' => $this->kind->value, 'provider' => $this->provider, 'ref' => $this->ref, 'title' => $this->name],
        };
    }

    /**
     * Null when the entry is about a title, not a creator.
     *
     * @param  array<mixed>  $entry
     */
    public static function fromArray(array $entry): ?self
    {
        $kind = match ($entry['type'] ?? null) {
            CreatorKind::Person->value => CreatorKind::Person,
            CreatorKind::Collection->value => CreatorKind::Collection,
            // The kind tells a studio from a network.
            self::COMPANY => ($entry['kind'] ?? null) === CreatorKind::Network->value ? CreatorKind::Network : CreatorKind::Company,
            default => null,
        };

        if ($kind === null) {
            return null;
        }

        if (! is_string($entry['provider'] ?? null) || ! is_string($entry['ref'] ?? null) || ! is_string($entry['title'] ?? null)) {
            throw InvalidBackupEntry::because('import.skip_backup_creator_incomplete', $entry);
        }

        return new self($kind, $entry['provider'], $entry['ref'], $entry['title']);
    }
}

<?php

namespace App\Integrations\VideTrack\Backup;

use App\Integrations\Import\Rows\SkipRow;
use RuntimeException;

/**
 * An entry of a backup that cannot be read as what its section holds. One
 * bad entry is skipped and the rest of the file still restores; the report
 * says what was missing ($key, an `import.*` key) and names the entry by its
 * title — never by what else it held.
 */
final class InvalidBackupEntry extends RuntimeException
{
    private function __construct(
        public readonly string $key,
        public readonly ?string $title,
    ) {
        parent::__construct($key);
    }

    /**
     * @param  array<mixed>  $entry
     */
    public static function because(string $key, array $entry): self
    {
        return new self($key, is_string($entry['title'] ?? null) && $entry['title'] !== '' ? $entry['title'] : null);
    }

    /** The skipped row, naming an entry without a title by where it stands in the file. */
    public function skip(string $place): SkipRow
    {
        return new SkipRow($this->key, ['label' => $this->title ?? $place]);
    }
}

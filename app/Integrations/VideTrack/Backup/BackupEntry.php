<?php

namespace App\Integrations\VideTrack\Backup;

/**
 * One entry of a backup section. Its shape in the file is written by
 * toArray() and read back by the class's own fromArray(), so the writer and
 * the restore cannot drift apart.
 */
interface BackupEntry
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(): array;
}

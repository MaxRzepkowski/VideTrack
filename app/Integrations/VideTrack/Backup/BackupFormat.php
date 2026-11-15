<?php

namespace App\Integrations\VideTrack\Backup;

/**
 * The envelope of a VideTrack backup: what names the file as ours and which
 * revision of the format it speaks. A section added later is additive within
 * a version — a file without it still reads, the section is simply empty —
 * so the version moves only when an existing field changes its meaning.
 */
final class BackupFormat
{
    public const NAME = 'videtrack-export';

    public const VERSION = 1;

    /**
     * Whether a decoded file is a backup this instance can read: ours, and
     * not from a newer revision than it knows.
     *
     * @phpstan-assert-if-true array<array-key, mixed> $data
     */
    public static function reads(mixed $data): bool
    {
        return is_array($data)
            && ($data['format'] ?? null) === self::NAME
            && is_int($data['version'] ?? null)
            && $data['version'] <= self::VERSION;
    }
}

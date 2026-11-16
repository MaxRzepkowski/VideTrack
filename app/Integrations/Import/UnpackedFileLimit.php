<?php

namespace App\Integrations\Import;

/**
 * The largest single file an import reads, uncompressed: a file is read
 * whole, and a small archive can unpack to far more than the upload limit.
 */
final class UnpackedFileLimit
{
    public static function bytes(): int
    {
        return config()->integer('tracker.imports.max_unpacked_megabytes') * 1024 * 1024;
    }
}

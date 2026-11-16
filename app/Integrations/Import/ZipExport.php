<?php

namespace App\Integrations\Import;

use ZipArchive;

/**
 * An uploaded ZIP export, read where it lies: which files it holds, how
 * large they unpack, their contents. A path that is not a readable ZIP
 * holds nothing.
 */
final class ZipExport
{
    private readonly ZipArchive $zip;

    private readonly bool $opened;

    public function __construct(private readonly string $path)
    {
        $this->zip = new ZipArchive;
        $this->opened = is_file($path) && $this->zip->open($path) === true;
    }

    public function has(string $name): bool
    {
        return $this->opened && $this->zip->locateName($name) !== false;
    }

    /**
     * Every file's path inside the archive, in archive order; folders left out.
     *
     * @return list<string>
     */
    public function names(): array
    {
        $names = [];

        for ($index = 0; $this->opened && $index < $this->zip->numFiles; $index++) {
            $name = $this->zip->getNameIndex($index);

            if ($name !== false && ! str_ends_with($name, '/')) {
                $names[] = $name;
            }
        }

        return $names;
    }

    /** How large a file unpacks, in bytes; null for one the archive does not hold. */
    public function size(string $name): ?int
    {
        $stat = $this->opened ? $this->zip->statName($name) : false;

        return $stat === false ? null : $stat['size'];
    }

    public function contents(string $name): ?string
    {
        $contents = $this->has($name) ? $this->zip->getFromName($name) : false;

        return $contents === false ? null : $contents;
    }
}

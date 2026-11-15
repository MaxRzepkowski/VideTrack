<?php

/*
 * Scratch files a test writes outside the app (an upload, a CSV, a lang tree):
 * made through these helpers, they are gone when the test ends.
 */

/** @return list<string> */
function &scratchPaths(): array
{
    static $paths = [];

    return $paths;
}

/** A fresh path in the system's temp directory, removed after the test. */
function scratchPath(string $prefix, string $suffix = ''): string
{
    $path = (string) tempnam(sys_get_temp_dir(), $prefix);
    $paths = &scratchPaths();
    $paths[] = $path;

    if ($suffix !== '') {
        $paths[] = $path .= $suffix;
    }

    return $path;
}

/** A fresh directory in the system's temp directory, removed after the test. */
function scratchDirectory(string $prefix): string
{
    $path = sys_get_temp_dir().'/'.$prefix.uniqid();
    mkdir($path, 0777, true);
    $paths = &scratchPaths();
    $paths[] = $path;

    return $path;
}

function removeScratch(): void
{
    $paths = &scratchPaths();

    foreach ($paths as $path) {
        if (is_dir($path)) {
            exec('rm -rf '.escapeshellarg($path));
        } elseif (is_file($path)) {
            unlink($path);
        }
    }

    $paths = [];
}

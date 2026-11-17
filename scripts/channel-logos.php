<?php

/*
 * The proportions of the notification services' logos, written into their
 * list so each <img> holds its box before the file loads. It reads the files
 * and never changes them.
 *
 *   php scripts/channel-logos.php
 *
 * Adding a logo: put the file in public/images/channels, name it in
 * resources/js/lib/channelLogos.json under the service's code (`file`,
 * `source`, `licence`), then run this.
 */

const MANIFEST = __DIR__.'/../resources/js/lib/channelLogos.json';
const FOLDER = __DIR__.'/../public/images/channels';

/**
 * @return array{int, int} width and height: a PNG's pixels, an SVG's viewBox
 */
function size(string $path): array
{
    if (str_ends_with($path, '.svg')) {
        $svg = (string) file_get_contents($path);

        if (preg_match('/viewBox\s*=\s*"\s*[-\d.]+[\s,]+[-\d.]+[\s,]+([\d.]+)[\s,]+([\d.]+)\s*"/i', $svg, $box) !== 1) {
            throw new RuntimeException("No viewBox in {$path}");
        }

        return [max(1, (int) round((float) $box[1])), max(1, (int) round((float) $box[2]))];
    }

    $info = getimagesize($path);

    if ($info === false) {
        throw new RuntimeException("Not an image: {$path}");
    }

    return [$info[0], $info[1]];
}

$manifest = json_decode((string) file_get_contents(MANIFEST), true, flags: JSON_THROW_ON_ERROR);

if (! is_array($manifest)) {
    throw new RuntimeException('The list is not an object.');
}

$listed = [];

foreach ($manifest as $kind => $entry) {
    $file = is_array($entry) && is_string($entry['file'] ?? null) ? $entry['file'] : throw new RuntimeException("No file for {$kind}");
    $listed[] = $file;
    [$width, $height] = size(FOLDER.'/'.$file);
    $manifest[$kind] = ['file' => $file, 'width' => $width, 'height' => $height, 'source' => $entry['source'] ?? '', 'licence' => $entry['licence'] ?? ''];
}

$unlisted = array_diff(array_map('basename', glob(FOLDER.'/*') ?: []), $listed);

if ($unlisted !== []) {
    throw new RuntimeException('Not in the list: '.implode(', ', $unlisted));
}

ksort($manifest);
file_put_contents(MANIFEST, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");

echo count($manifest)." logos measured.\n";

<?php

namespace App\Support;

use ErrorException;
use GdImage;
use InvalidArgumentException;

/**
 * An uploaded picture re-encoded for publishing as an avatar: a square WebP
 * of bounded size. Re-encoding keeps none of the original's metadata, so a
 * phone photo's EXIF (location included) never reaches the public URL; the
 * EXIF orientation is applied first, so the picture still stands upright.
 */
final class AvatarImage
{
    /** Side of the stored square, in pixels: twice the largest avatar shown. */
    private const SIZE = 512;

    private const QUALITY = 85;

    public static function encode(string $path): string
    {
        $contents = (string) file_get_contents($path);
        $image = imagecreatefromstring($contents);

        if (! $image instanceof GdImage) {
            throw new InvalidArgumentException('The avatar is not a readable image.');
        }

        $image = self::upright($image, $path);
        $side = min(imagesx($image), imagesy($image));
        $target = min($side, self::SIZE);

        // The centre square, scaled down; avatars are always shown square.
        $square = imagecreatetruecolor($target, $target);
        imagealphablending($square, false);
        imagesavealpha($square, true);
        imagecopyresampled(
            $square,
            $image,
            0,
            0,
            intdiv(imagesx($image) - $side, 2),
            intdiv(imagesy($image) - $side, 2),
            $target,
            $target,
            $side,
            $side,
        );

        ob_start();
        imagewebp($square, null, self::QUALITY);

        return (string) ob_get_clean();
    }

    private static function upright(GdImage $image, string $path): GdImage
    {
        $orientation = 1;

        if (function_exists('exif_read_data') && exif_imagetype($path) === IMAGETYPE_JPEG) {
            try {
                $orientation = Numbers::int(exif_read_data($path)['Orientation'] ?? 1) ?? 1;
            } catch (ErrorException) {
                // Broken EXIF: the picture is still fine, just not rotated.
            }
        }

        $rotated = match ($orientation) {
            3 => imagerotate($image, 180, 0),
            6 => imagerotate($image, -90, 0),
            8 => imagerotate($image, 90, 0),
            default => $image,
        };

        return $rotated instanceof GdImage ? $rotated : $image;
    }
}

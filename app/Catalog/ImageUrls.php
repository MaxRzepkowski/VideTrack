<?php

namespace App\Catalog;

/**
 * A provider's image as an address anyone can load: TMDB hands out paths
 * under its image CDN, the other providers whole URLs. For whoever cannot
 * resolve a path itself — an API client, a Discord message.
 */
final class ImageUrls
{
    /** CDN size for a poster or a profile picture. */
    public const POSTER = 'w500';

    /** CDN size for a backdrop or a logo. */
    public const BACKDROP = 'w1280';

    public static function of(?string $path, string $size): ?string
    {
        if ($path === null || $path === '' || str_starts_with($path, 'http')) {
            return $path === '' ? null : $path;
        }

        return self::base().'/'.$size.$path;
    }

    /** Where provider-relative image paths hang off; the pages build their own sizes on it. */
    public static function base(): string
    {
        return rtrim(config()->string('tracker.tmdb.image_base_url'), '/');
    }
}

<?php

namespace App\Support;

use Illuminate\Http\Client\ConnectionException;

/**
 * Why an outbound connection failed, safe to log and to show in task runs.
 *
 * cURL appends the full request URI to its message, query string included, so
 * a key sent as ?api_key= (TMDB v3, ComicVine) would travel with the message
 * into logs and the database. Only the cURL explanation is kept; a message in
 * any other shape is not repeated at all.
 */
final class ConnectionFailure
{
    private const FALLBACK = 'connection failed';

    public static function reason(ConnectionException $exception): string
    {
        $matched = preg_match(
            '/^cURL error \d+: (.+?)(?: \(see https?:\/\/[^)]*\))?(?: for https?:\/\/\S*)?$/s',
            $exception->getMessage(),
            $parts,
        );

        return $matched === 1 ? trim($parts[1]) : self::FALLBACK;
    }
}

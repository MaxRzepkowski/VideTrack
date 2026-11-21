<?php

namespace App\Catalog\Providers\Tmdb;

use App\Catalog\ProviderCredential;
use App\Catalog\ProviderException;
use Illuminate\Http\Client\PendingRequest;

/**
 * The instance's TMDB key, read and checked in one place, on a request the
 * way its kind wants it: a v4
 * read access token (a JWT, so "eyJ…") as a bearer header, a v3 key in the
 * query string.
 */
final class TmdbKey
{
    /**
     * @throws ProviderException when the instance has no key
     */
    public static function on(PendingRequest $request): PendingRequest
    {
        $key = ProviderCredential::read('tracker.tmdb.api_key', 'TMDB_API_KEY');

        return str_starts_with($key, 'eyJ') ? $request->withToken($key) : $request->withQueryParameters(['api_key' => $key]);
    }
}

<?php

namespace App\Integrations\Anime;

use App\Support\ConnectionFailure;
use App\Support\HttpRetry;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/** The community anime id map (Kometa-Team/Anime-IDs) at a given revision, from its raw-file host. */
final class AnimeIdMapClient
{
    private const SERVICE = 'Anime-IDs';

    /**
     * The map's file at that revision.
     *
     * @throws AnimeIdMapUnavailable
     */
    public function revision(string $revision): string
    {
        try {
            $response = Http::timeout(30)
                ->retry(2, 500, HttpRetry::isTransient(...), throw: false)
                ->get(rtrim(config()->string('tracker.imports.anime_ids_url'), '/')."/{$revision}/anime_ids.json");
        } catch (ConnectionException $e) {
            throw new AnimeIdMapUnavailable('import.service_unreachable', ['service' => self::SERVICE, 'reason' => ConnectionFailure::reason($e)]);
        }

        if ($response->failed()) {
            throw new AnimeIdMapUnavailable('import.service_answered_try_later', ['service' => self::SERVICE, 'status' => $response->status()]);
        }

        return $response->body();
    }
}

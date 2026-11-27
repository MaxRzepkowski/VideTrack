<?php

namespace App\Integrations\Anime;

use App\Integrations\ServiceHttpClient;
use App\Support\HttpRetry;
use Illuminate\Http\Client\Response;

/** The community anime id map (Kometa-Team/Anime-IDs) at a given revision, from its raw-file host. */
final readonly class AnimeIdMapClient
{
    private const SERVICE = 'Anime-IDs';

    private const TRIES = 2;

    private const RETRY_MS = 500;

    public function __construct(private ServiceHttpClient $http) {}

    /**
     * The map's file at that revision.
     *
     * @throws AnimeIdMapUnavailable
     */
    public function revision(string $revision): string
    {
        $request = $this->http->retrying($this->http->request(), self::TRIES, self::RETRY_MS, HttpRetry::isTransient(...));
        $response = $this->http->reach(self::SERVICE, AnimeIdMapUnavailable::class, fn (): Response => $request->get(rtrim(config()->string('tracker.imports.anime_ids_url'), '/')."/{$revision}/anime_ids.json"));

        if ($response->failed()) {
            throw new AnimeIdMapUnavailable('import.service_answered_try_later', ['service' => self::SERVICE, 'status' => $response->status()]);
        }

        return $response->body();
    }
}

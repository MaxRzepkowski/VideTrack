<?php

namespace App\Integrations\Steam;

use App\Support\HttpRetry;
use App\Support\Payload;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * The two Steam Web API calls an import needs, with the user's own key:
 * ISteamUser/ResolveVanityURL (a profile name to a SteamID64) and
 * IPlayerService/GetOwnedGames (the whole library in one answer).
 * Shapes: partner.steamgames.com/doc/webapi, the Web API pages of the
 * official TF2 wiki, and the Yamtrack/Floppy importers' fixtures.
 *
 * The key travels in the query string, as Steam wants it — it never goes
 * into an exception message. A rejected key or profile is final; only a
 * dropped connection, a 5xx or a 429 is tried again.
 */
class SteamClient
{
    /** Where a user makes their own Steam Web API key. */
    public const KEY_URL = 'https://steamcommunity.com/dev/apikey';

    private const TIMEOUT_SECONDS = 30;

    /**
     * The SteamID64 behind a profile's custom name.
     *
     * @throws SteamNotFound|SteamRejected|SteamUnavailable
     */
    public function resolveVanityName(string $key, string $name): string
    {
        $response = $this->get($key, '/ISteamUser/ResolveVanityURL/v1/', ['vanityurl' => $name]);
        $steamId = $response->json('response.steamid');

        if ($response->json('response.success') !== 1 || ! is_string($steamId)) {
            throw new SteamNotFound('import.steam_knows_no_profile_by_that');
        }

        return $steamId;
    }

    /**
     * Every game on the profile, with play time. An answer without a games
     * list is Steam's way of saying the library is private (or empty).
     *
     * @return list<array<array-key, mixed>>
     *
     * @throws SteamRejected|SteamUnavailable
     */
    public function ownedGames(string $key, string $steamId): array
    {
        $response = $this->get($key, '/IPlayerService/GetOwnedGames/v1/', [
            'steamid' => $steamId,
            'include_appinfo' => '1',
            'include_played_free_games' => '1',
        ]);

        $games = $response->json('response.games');

        if (! is_array($games)) {
            throw new SteamRejected('import.steam_shows_no_games_on_this');
        }

        return Payload::list($games);
    }

    /**
     * @param  array<string, string>  $query
     *
     * @throws SteamRejected|SteamUnavailable
     */
    private function get(string $key, string $path, array $query): Response
    {
        try {
            $response = Http::baseUrl(rtrim(config()->string('tracker.steam.base_url'), '/'))
                ->timeout(self::TIMEOUT_SECONDS)
                ->acceptJson()
                ->withUserAgent(config()->string('tracker.http.user_agent'))
                ->retry(3, fn (int $attempt): int => 2000 * 2 ** ($attempt - 1), HttpRetry::isTransientOrThrottled(...), throw: false)
                ->get($path, [...$query, 'key' => $key, 'format' => 'json']);
        } catch (ConnectionException) {
            throw new SteamUnavailable('import.steam_could_not_be_reached_try');
        }

        return match (true) {
            in_array($response->status(), [401, 403], true) => throw new SteamRejected('import.steam_did_not_accept_this_key'),
            $response->status() === 400 => throw new SteamRejected('import.enter_your_steam_profile_address_or'),
            ! $response->successful() => throw new SteamUnavailable('import.steam_could_not_be_reached_try'),
            default => $response,
        };
    }
}

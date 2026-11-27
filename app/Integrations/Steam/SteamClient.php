<?php

namespace App\Integrations\Steam;

use App\Integrations\ServiceHttpClient;
use App\Support\HttpRetry;
use App\Support\Payload;
use App\Support\ServiceBusy;
use Illuminate\Http\Client\Response;

/**
 * The two Steam Web API calls an import needs, with the user's own key:
 * ISteamUser/ResolveVanityURL (a profile name to a SteamID64) and
 * IPlayerService/GetOwnedGames (the whole library in one answer).
 * Shapes: partner.steamgames.com/doc/webapi, the Web API pages of the
 * official TF2 wiki, and the Yamtrack/Floppy importers' fixtures.
 *
 * The key travels in the query string, as Steam wants it — it never goes
 * into an exception message. A rejected key or profile is final; only a
 * dropped connection, a 5xx or a 429 is tried again, and a 429 still standing
 * after the tries says Steam asked to wait.
 */
class SteamClient
{
    /** Where a user makes their own Steam Web API key. */
    public const KEY_URL = 'https://steamcommunity.com/dev/apikey';

    public const TRIES = 3;

    private const FIRST_RETRY_MS = 2000;

    /** Steam names no window for its limit: a 429 without a Retry-After waits a minute. */
    private const DEFAULT_WAIT_SECONDS = 60;

    public function __construct(private readonly ServiceHttpClient $http) {}

    /**
     * The SteamID64 behind a profile's custom name.
     *
     * @throws SteamNotFound|SteamRejected|SteamUnavailable|ServiceBusy
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
     * @throws SteamRejected|SteamUnavailable|ServiceBusy
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
     * @throws SteamRejected|SteamUnavailable|ServiceBusy
     */
    private function get(string $key, string $path, array $query): Response
    {
        $request = $this->http->retrying($this->http->request(config()->string('tracker.steam.base_url')), self::TRIES, self::FIRST_RETRY_MS, HttpRetry::isTransientOrThrottled(...));
        $response = $this->http->unlessBusy(
            SteamSource::SERVICE,
            $this->http->reach(SteamSource::SERVICE, SteamUnavailable::class, fn (): Response => $request->get($path, [...$query, 'key' => $key, 'format' => 'json'])),
            self::DEFAULT_WAIT_SECONDS,
        );

        return match (true) {
            in_array($response->status(), [401, 403], true) => throw new SteamRejected('import.steam_did_not_accept_this_key'),
            $response->status() === 400 => throw new SteamRejected('import.enter_your_steam_profile_address_or'),
            HttpRetry::isOutage($response) => throw new SteamUnavailable('import.service_answered_try_later', ['service' => SteamSource::SERVICE, 'status' => $response->status()]),
            ! $response->successful() => throw new SteamRejected('import.service_answered_to', ['service' => SteamSource::SERVICE, 'status' => $response->status(), 'path' => $path]),
            default => $response,
        };
    }
}

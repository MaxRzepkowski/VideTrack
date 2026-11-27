<?php

namespace App\Integrations\RetroAchievements;

use App\Integrations\ServiceHttpClient;
use App\Support\HttpRetry;
use App\Support\Payload;
use App\Support\ServiceBusy;
use Illuminate\Http\Client\Response;

/**
 * RetroAchievements' web API as its documentation and source describe it
 * (api-docs.retroachievements.org; RAWeb, public/API): every call carries a
 * web API key in `y`, and API_GetUserCompletionProgress lists the games a
 * player has played, up to 500 a page, newest progress first.
 *
 * The key is the user's own, typed into the import form; it travels in the
 * query string, as RetroAchievements wants it, and never goes into an
 * exception message. A refused key or username is final; only a dropped
 * connection or a 5xx is asked again at once, and a 429 is waited out by
 * the run.
 */
class RetroAchievementsClient
{
    public const PAGE_SIZE = 500;

    private const RETRIES = 3;

    private const FIRST_RETRY_MS = 2000;

    /** A 429 without a Retry-After waits a minute. */
    private const DEFAULT_WAIT_SECONDS = 60;

    public function __construct(private readonly ServiceHttpClient $http) {}

    /**
     * Every game the player has progress in, page by page.
     *
     * @return list<array<array-key, mixed>>
     *
     * @throws RetroAchievementsRejected|RetroAchievementsUnavailable|ServiceBusy
     */
    public function completionProgress(string $username, string $key): array
    {
        $games = [];

        do {
            $page = $this->get('/API_GetUserCompletionProgress.php', $key, ['u' => $username, 'c' => (string) self::PAGE_SIZE, 'o' => (string) count($games)]);
            $results = Payload::list($page['Results'] ?? null);
            $games = [...$games, ...$results];
        } while ($results !== [] && count($games) < (Payload::int($page, 'Total') ?? 0));

        return $games;
    }

    /**
     * @param  array<string, string>  $query
     * @return array<array-key, mixed>
     *
     * @throws RetroAchievementsRejected|RetroAchievementsUnavailable|ServiceBusy
     */
    private function get(string $path, string $key, array $query): array
    {
        $request = $this->http->retrying($this->http->request(config()->string('tracker.retroachievements.base_url')), self::RETRIES, self::FIRST_RETRY_MS, HttpRetry::isTransient(...));
        $response = $this->http->unlessBusy(
            RetroAchievementsSource::SERVICE,
            $this->http->reach(RetroAchievementsSource::SERVICE, RetroAchievementsUnavailable::class, fn (): Response => $request->get($path, [...$query, 'y' => $key])),
            self::DEFAULT_WAIT_SECONDS,
        );

        return match (true) {
            in_array($response->status(), [401, 403], true) => throw new RetroAchievementsRejected('import.retroachievements_did_not_accept_this_key'),
            $response->status() === 422 => throw new RetroAchievementsRejected('import.retroachievements_does_not_take_this_username'),
            ! $response->successful() => throw new RetroAchievementsUnavailable('import.service_answered_try_later', ['service' => RetroAchievementsSource::SERVICE, 'status' => $response->status()]),
            default => Payload::map($response->json()),
        };
    }
}

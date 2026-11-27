<?php

namespace App\Integrations\Anime;

use App\Catalog\Providers\Concerns\ThrottlesRequests;
use App\Support\Payload;
use App\Support\ServiceBusy;

/** A user's AniList lists, in one GraphQL request. */
final class AnilistClient
{
    use ThrottlesRequests;

    /** AniList allows 30 requests a minute; an import sends one. */
    private const RATE_LIMIT_PER_MINUTE = 25;

    public function __construct(private readonly AnimeListRequests $requests) {}

    /**
     * The `data` of a GraphQL answer to `$query` for this user.
     *
     * @return array<array-key, mixed>
     *
     * @throws AnimeListUnavailable|AnimeListRefused|AnimeListNotFound|ServiceBusy
     */
    public function query(string $query, string $username): array
    {
        $response = $this->requests->send('AniList', fn () => $this->throttle('anilist-api', self::RATE_LIMIT_PER_MINUTE, 60), fn () => $this->requests->to()
            ->post(config()->string('tracker.imports.anilist_url'), [
                'query' => $query,
                'variables' => ['userName' => $username],
            ]));

        $body = Payload::map($response->json());
        $error = Payload::string(Payload::list($body['errors'] ?? null)[0] ?? [], 'message');

        if ($error === 'User not found') {
            throw new AnimeListNotFound('import.anime_list_no_user', ['service' => 'AniList', 'username' => $username]);
        }

        if ($error === 'Private User') {
            throw new AnimeListRefused('import.anime_list_private', ['service' => 'AniList', 'username' => $username]);
        }

        $this->requests->refuseFailed('AniList', $response);

        $data = $body['data'] ?? null;

        if (! is_array($data)) {
            throw new AnimeListUnavailable('import.service_answered_try_later', ['service' => 'AniList', 'status' => $response->status()]);
        }

        return $data;
    }
}

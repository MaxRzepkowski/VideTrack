<?php

namespace App\Integrations\Anime;

use App\Catalog\ProviderCredential;
use App\Catalog\Providers\Concerns\ThrottlesRequests;
use App\Catalog\Providers\Mal\MalClient;
use App\Support\Payload;
use App\Support\ServiceBusy;

/**
 * A user's MyAnimeList lists, page by page. Shares the instance's MAL client
 * id with the manga provider, and so its request budget: both throttle on
 * MalClient's limiter, never on a copy of it.
 */
final class MalListClient
{
    use ThrottlesRequests;

    public function __construct(private readonly AnimeListRequests $requests) {}

    /**
     * One page of the user's anime or manga list.
     *
     * @return array<array-key, mixed>
     *
     * @throws AnimeListUnavailable|AnimeListRefused|AnimeListNotFound|ServiceBusy
     */
    public function page(string $username, string $medium, int $offset, int $limit): array
    {
        $response = $this->requests->send('MyAnimeList', fn () => $this->throttle(MalClient::RATE_LIMITER, MalClient::RATE_LIMIT_PER_MINUTE, 60), fn () => $this->requests->to(config()->string('tracker.mal.base_url'))
            ->withHeaders(['X-MAL-CLIENT-ID' => ProviderCredential::read('tracker.mal.client_id', 'MAL_CLIENT_ID')])
            ->get('users/'.rawurlencode($username).'/'.$medium.'list', [
                'fields' => 'num_episodes,num_chapters,list_status{comments,num_times_rewatched,num_times_reread}',
                'nsfw' => 'true',
                'limit' => $limit,
                'offset' => $offset,
            ]));

        if ($response->status() === 404) {
            throw new AnimeListNotFound('import.anime_list_no_user', ['service' => 'MyAnimeList', 'username' => $username]);
        }

        $this->requests->refuseFailed('MyAnimeList', $response);

        return Payload::map($response->json());
    }
}

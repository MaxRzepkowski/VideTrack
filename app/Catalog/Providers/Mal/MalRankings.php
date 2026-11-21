<?php

namespace App\Catalog\Providers\Mal;

use App\Catalog\Data\DiscoverFilter;
use App\Catalog\Data\DiscoverFilterOption;
use App\Catalog\Data\MediaSummary;
use App\Catalog\Data\Paginated;
use App\Catalog\DiscoverFilterKind;
use App\Catalog\MatureContent;
use App\Catalog\ProviderException;
use App\Catalog\ProviderRejected;
use App\Support\Payload;

/**
 * MAL's rankings of all manga as the discover page's filtered listing: one
 * select picks the ranking. MAL browses neither genres nor years, so a
 * ranking is the only filter.
 *
 * MAL tells only whether a ranking goes on, never how long it is, and its
 * rankings hold novels and adult titles the catalog leaves out. So the
 * listing is the ranking's top RANKING_DEPTH, fetched in one answer and
 * narrowed here: a complete set, whose pages and total are true.
 */
final readonly class MalRankings
{
    /** The filter's key in the page's `f[...]` parameters. */
    public const FILTER = 'ranking';

    /** Manga per page of the listing. */
    public const PAGE_SIZE = 24;

    /** How deep into a ranking the listing goes, within the most MAL hands out in one answer (500). */
    public const RANKING_DEPTH = 480;

    public function __construct(
        private MalClient $client,
        private MalPayload $payload,
    ) {}

    /** @return list<DiscoverFilter> */
    public function filters(): array
    {
        $options = array_map(
            fn (MangaRankingType $ranking): DiscoverFilterOption => new DiscoverFilterOption($ranking->value, $ranking->label()),
            MangaRankingType::cases(),
        );

        return [new DiscoverFilter(self::FILTER, __('discover.ranking'), DiscoverFilterKind::Select, $options)];
    }

    /**
     * One page of the chosen ranking. A ranking not offered names nothing:
     * no aged link can pass a raw value on to MAL.
     *
     * @param  array<string, string|list<string>|array{from: int|null, to: int|null}>  $filters
     * @return Paginated<MediaSummary>
     *
     * @throws ProviderException|ProviderRejected
     */
    public function page(array $filters, string $fields, MatureContent $mature, int $page): Paginated
    {
        $ranking = Payload::enum($filters, self::FILTER, MangaRankingType::class);

        if ($ranking === null) {
            return new Paginated([], 1, 1, 0);
        }

        $data = $this->client->get(
            '/manga/ranking',
            ['ranking_type' => $ranking->value, 'limit' => self::RANKING_DEPTH, 'fields' => $fields, ...MalClient::ALL_RATINGS],
            $this->client->ttl('listing'),
            requireKey: 'data',
        );

        $cards = $this->payload->cards(Payload::list($data['data'] ?? null), $mature);
        $lastPage = max(1, (int) ceil(count($cards) / self::PAGE_SIZE));
        $page = min(max(1, $page), $lastPage);

        return new Paginated(array_slice($cards, ($page - 1) * self::PAGE_SIZE, self::PAGE_SIZE), $page, $lastPage, count($cards));
    }
}

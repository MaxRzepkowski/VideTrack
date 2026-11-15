<?php

namespace App\Catalog\Providers\Tvmaze;

use App\Catalog\Contracts\SupportsAirSchedule;
use App\Catalog\ItemMetadata;
use App\Models\MediaItem;
use App\Support\Payload;

/**
 * The air schedule from TVmaze: maps a show to TVmaze and reads its
 * per-episode broadcast instants. Shared by the calendar backfill job and the
 * season page's deferred air-time prop — both go through the client's 12h
 * cache, so a show costs at most two TVmaze calls per half-day regardless of
 * surface.
 */
final readonly class TvmazeSchedule implements SupportsAirSchedule
{
    public function __construct(private TvmazeClient $client) {}

    public function showIdKey(): string
    {
        return 'tvmazeId';
    }

    public function findShow(MediaItem $show): ?int
    {
        $metadata = ItemMetadata::fromStored($show->metadata);

        return $this->resolveShowId($metadata->tvdbId(), $metadata->imdbId(), $show->title, $show->year);
    }

    public function airTimes(int $showId): array
    {
        return $this->episodeTimes($showId);
    }

    public function cachedAirTimes(int $showId): ?array
    {
        /** @var list<array<string, mixed>>|null $episodes */
        $episodes = $this->client->getCached("/shows/{$showId}/episodes");

        return $episodes === null ? null : $this->parseTimes($episodes);
    }

    /**
     * TVmaze show id from a deterministic external-id lookup (TheTVDB, then
     * IMDb), falling back to a name search matched on premiere year so a
     * remake never binds to the wrong schedule.
     */
    private function resolveShowId(?int $tvdbId, ?string $imdbId, string $title, ?int $year): ?int
    {
        if ($tvdbId !== null && $tvdbId > 0) {
            $found = Payload::int($this->client->get('/lookup/shows', ['thetvdb' => $tvdbId]) ?? [], 'id');

            if ($found !== null) {
                return $found;
            }
        }

        if ($imdbId !== null && $imdbId !== '') {
            $found = Payload::int($this->client->get('/lookup/shows', ['imdb' => $imdbId]) ?? [], 'id');

            if ($found !== null) {
                return $found;
            }
        }

        return $this->searchShowId($title, $year);
    }

    /**
     * Broadcast instants keyed by "season:number", kept only when TVmaze
     * reports a real airtime — the empty-airtime placeholder (streaming
     * noon-UTC) is dropped so we never invent a time.
     *
     * @return array<string, string> season:number => ISO-8601 airstamp
     */
    private function episodeTimes(int $tvmazeId): array
    {
        return $this->parseTimes(Payload::list($this->client->get("/shows/{$tvmazeId}/episodes")));
    }

    /**
     * @param  list<array<array-key, mixed>>  $episodes
     * @return array<string, string> season:number => ISO-8601 airstamp
     */
    private function parseTimes(array $episodes): array
    {
        $times = [];

        foreach ($episodes as $episode) {
            $airstamp = Payload::string($episode, 'airstamp');

            if (Payload::string($episode, 'airtime') === null || $airstamp === null) {
                continue;
            }

            $season = Payload::int($episode, 'season') ?? 0;
            $number = Payload::int($episode, 'number') ?? 0;

            $times["{$season}:{$number}"] = $airstamp;
        }

        return $times;
    }

    private function searchShowId(string $title, ?int $year): ?int
    {
        if ($year === null) {
            return null;
        }

        foreach (Payload::list($this->client->get('/search/shows', ['q' => $title])) as $result) {
            $premiered = data_get($result, 'show.premiered');
            $id = data_get($result, 'show.id');

            if (is_string($premiered) && Payload::yearOf($premiered) === $year && is_int($id)) {
                return $id;
            }
        }

        return null;
    }
}

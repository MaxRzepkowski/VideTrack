<?php

namespace App\Catalog\Providers\Tmdb;

use App\Catalog\Data\EpisodeRating;
use App\Catalog\Data\MediaSummary;
use App\Catalog\Data\SeasonRatings;
use App\Support\Locales;
use App\Support\Payload;

/**
 * A show's episodes in bulk: every season's ratings for the ratings grid, and
 * every episode as a leaf of the hierarchy (cascades).
 */
final readonly class TmdbEpisodeLists
{
    public function __construct(
        private TmdbClient $client,
        private TmdbPayload $payload,
        private TmdbTitles $titles,
        private TmdbTitleParts $parts,
    ) {}

    /**
     * @return list<SeasonRatings>
     */
    public function episodeRatings(string $ref, string $locale): array
    {
        // The show details response (already cached from rendering the page)
        // lists the season numbers.
        $numbers = [];

        foreach ($this->titles->showDetails($ref, $locale, Locales::region($locale))->children as $season) {
            if (($season->number ?? 0) > 0) {
                $numbers[] = (int) $season->number;
            }
        }

        sort($numbers);

        $seasons = [];

        foreach ($this->seasonEpisodes($ref, $numbers, $locale) as $number => $rawEpisodes) {
            $episodes = [];

            foreach ($rawEpisodes as $episode) {
                $episodes[] = new EpisodeRating(
                    episode: Payload::int($episode, 'episode_number') ?? 0,
                    title: Payload::text($episode, 'name'),
                    rating: $this->payload->rating($episode),
                    airDate: Payload::string($episode, 'air_date'),
                );
            }

            if ($episodes !== []) {
                $seasons[] = new SeasonRatings($number, $episodes);
            }
        }

        return $seasons;
    }

    /**
     * Every episode of a show in the same batched requests the ratings chart
     * uses — one round-trip per 20 seasons, not one per season — mapped like
     * the season page maps them, badges included, so a stored tree built from
     * here holds what the season page would have given it.
     *
     * @return list<MediaSummary>
     */
    public function leaves(string $type, string $ref, string $locale): array
    {
        if ($type !== 'show') {
            return [];
        }

        $show = $this->titles->showDetails($ref, $locale, Locales::region($locale));
        $numbers = [];

        foreach ($show->children as $season) {
            $numbers[] = (int) ($season->number ?? 0);
        }

        sort($numbers);

        $episodes = [];

        foreach ($this->seasonEpisodes($ref, $numbers, $locale) as $number => $rawEpisodes) {
            $closesEndedShow = $this->titles->lastSeasonOfEndedShow($number, $show->extra['seasonCount'] ?? null, $show->status);

            foreach (array_values($rawEpisodes) as $index => $episode) {
                $episodes[] = $this->parts->episodeSummary($episode, $ref, $number, opensSeason: $index === 0, closesEndedShow: $closesEndedShow);
            }
        }

        return $episodes;
    }

    /**
     * Raw episode payloads of the given seasons, keyed by season number. TMDB
     * caps append_to_response at 20 sub-requests, so a long-running show costs
     * ceil(seasons/20) calls instead of one per season. The batched response is
     * cached under its own key with the details TTL, so the second reader (the
     * ratings chart and the total runtime want the same episodes) pays nothing.
     *
     * @param  list<int>  $numbers
     * @return array<int, array<int, array<array-key, mixed>>>
     */
    private function seasonEpisodes(string $ref, array $numbers, string $locale): array
    {
        $seasons = [];

        foreach (array_chunk($numbers, TmdbPayload::APPEND_LIMIT) as $chunk) {
            $data = $this->client->get("tv/{$ref}", [
                'language' => $this->payload->language($locale),
                'append_to_response' => implode(',', array_map(fn (int $n): string => "season/{$n}", $chunk)),
            ], $this->payload->ttl('details'));

            foreach ($chunk as $number) {
                $rawEpisodes = Payload::list(data_get($data, "season/{$number}.episodes"));

                $seasons[$number] = $rawEpisodes;
            }
        }

        return $seasons;
    }
}

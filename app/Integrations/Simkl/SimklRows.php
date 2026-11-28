<?php

namespace App\Integrations\Simkl;

use App\Catalog\ExternalIdSource;
use App\Catalog\MediaKind;
use App\Enums\MediaUserStatus;
use App\Integrations\Anime\AnimeIdMap;
use App\Integrations\Anime\AnimeMapping;
use App\Integrations\EpisodeLabel;
use App\Integrations\Import\Data\ImportDrop;
use App\Integrations\Import\Data\ImportMark;
use App\Integrations\Import\Data\ImportPlay;
use App\Integrations\Import\Data\ImportRating;
use App\Integrations\Import\Data\ImportStatus;
use App\Integrations\Import\Data\MediaSubject;
use App\Integrations\Import\Rows\ImportRow;
use App\Integrations\Import\Rows\MediaRow;
use App\Integrations\Import\Rows\SkipRow;
use App\Library\PlayDating;
use App\Support\Payload;
use Generator;

/**
 * One Simkl library entry (GET /sync/all-items) in our rows.
 *
 * Every watched episode is its own play at its own watched_at. The run asks
 * for include_all_episodes=yes, so an episode Simkl holds no date for comes
 * with the show's last-watched time — the rule the MAL import follows
 * (completed ticks the episodes with the completion date), and Simkl, unlike MAL, knows which episodes those are.
 *
 * The status of a show is read off its episodes, so "watching" and
 * "completed" put nothing on the show; on hold, dropped, plan to watch and
 * the rating do. Movies know no "watching" or "hold" at Simkl.
 *
 * Anime is known by MyAnimeList/AniDB ids only, so it goes through the
 * Kometa map like the MAL import; its episodes carry Simkl's own TVDB
 * numbering, used when present.
 */
final readonly class SimklRows
{
    public function __construct(private AnimeIdMap $anime) {}

    /**
     * @param  array<array-key, mixed>  $entry
     * @return Generator<int, ImportRow>
     */
    public function movie(array $entry): Generator
    {
        $movie = $this->object($entry, 'movie');
        $label = $this->label($movie);
        $subject = $this->rootSubject('movie', $movie, $label);

        if ($subject === null) {
            yield new SkipRow('import.skip_simkl_no_ids', ['label' => $label]);

            return;
        }

        yield $this->filmRow($subject, $entry);
    }

    /**
     * @param  array<array-key, mixed>  $entry
     * @return Generator<int, ImportRow>
     */
    public function show(array $entry): Generator
    {
        $show = $this->object($entry, 'show');
        $label = $this->label($show);
        $ids = $this->ids($show);
        $tmdb = $ids['tmdb'] ?? null;
        $series = ExternalIdSource::only($ids, ExternalIdSource::Imdb, ExternalIdSource::Tvdb);

        if ($tmdb === null && $series === []) {
            yield new SkipRow('import.skip_simkl_no_ids', ['label' => $label]);

            return;
        }

        foreach ($this->episodes($entry) as [$season, $number, $play]) {
            $episodeLabel = EpisodeLabel::of($label, $season, $number);

            yield new MediaRow(
                $tmdb !== null
                    ? MediaSubject::below('show', 'tmdb', $tmdb, [$season, $number], $episodeLabel)
                    : MediaSubject::external(MediaKind::Episode, [], $episodeLabel, $series, $season, $number),
                plays: [$play],
            );
        }

        $root = $tmdb !== null
            ? MediaSubject::direct('show', 'tmdb', $tmdb, $label)
            : MediaSubject::external(MediaKind::Show, $series, $label);

        yield from $this->showRow($root, $entry);
    }

    /**
     * @param  array<array-key, mixed>  $entry
     * @return Generator<int, ImportRow>
     */
    public function anime(array $entry): Generator
    {
        $show = $this->object($entry, 'show');
        $ids = $this->ids($show);
        $label = $this->label($show).(isset($ids['mal']) ? " (MAL {$ids['mal']})" : '');

        $mapping = isset($ids['mal']) && ctype_digit($ids['mal']) ? $this->anime->forMal((int) $ids['mal']) : null;

        if ($mapping === null && ! $this->anime->isAvailable()) {
            yield new SkipRow('import.skip_anime_map_unavailable', ['label' => $label]);

            return;
        }

        if ($mapping !== null && $mapping->isSeason()) {
            yield from $this->animeSeason($entry, $mapping, $label);

            return;
        }

        // A film or special: by its own IMDb id from the map, else by the
        // ids Simkl may hold for it.
        $imdb = $mapping?->standaloneImdb();
        $subject = $imdb !== null
            ? MediaSubject::external(MediaKind::Movie, [ExternalIdSource::Imdb->value => $imdb], $label)
            : (($entry['anime_type'] ?? null) === 'movie' ? $this->rootSubject('movie', $show, $label) : null);

        if ($subject === null) {
            yield new SkipRow($mapping === null ? 'import.skip_anime_not_in_map' : 'import.skip_anime_special_without_id', ['label' => $label]);

            return;
        }

        yield $this->filmRow($subject, $entry);
    }

    /**
     * @param  array<array-key, mixed>  $entry
     * @return Generator<int, ImportRow>
     */
    private function animeSeason(array $entry, AnimeMapping $mapping, string $label): Generator
    {
        $series = [ExternalIdSource::Tvdb->value => (string) $mapping->tvdbId];

        foreach ($this->episodes($entry, $mapping) as [$season, $number, $play]) {
            yield new MediaRow(
                MediaSubject::external(MediaKind::Episode, [], EpisodeLabel::of($label, $season, $number), $series, $season, $number),
                plays: [$play],
            );
        }

        yield from $this->showRow(MediaSubject::external(MediaKind::Show, $series, $label), $entry);
    }

    /**
     * The watched episodes as [season, number, viewing]. An episode Simkl kept
     * no date for takes the show's last watch, which moves with every later
     * episode — so it is told apart by its run, not by that date. An anime entry is
     * one AniDB season with episodes numbered from 1; Simkl's own TVDB
     * placement wins, the map's season and offset stand in for it.
     *
     * @param  array<array-key, mixed>  $entry
     * @return list<array{int, int, ImportPlay}>
     */
    private function episodes(array $entry, ?AnimeMapping $mapping = null): array
    {
        $episodes = [];
        $fallback = Payload::instant($entry['last_watched_at'] ?? null);

        foreach (is_array($entry['seasons'] ?? null) ? $entry['seasons'] : [] as $season) {
            if (! is_array($season)) {
                continue;
            }

            foreach (is_array($season['episodes'] ?? null) ? $season['episodes'] : [] as $episode) {
                if (! is_array($episode) || ! is_int($episode['number'] ?? null)) {
                    continue;
                }

                $own = Payload::instant($episode['watched_at'] ?? null);
                $at = $own === null
                    ? new ImportPlay($fallback, dating: PlayDating::Activity)
                    : new ImportPlay($own);
                $tvdb = is_array($episode['tvdb'] ?? null) ? $episode['tvdb'] : [];

                if ($mapping !== null) {
                    $episodes[] = is_int($tvdb['season'] ?? null) && is_int($tvdb['episode'] ?? null)
                        ? [$tvdb['season'], $tvdb['episode'], $at]
                        : [...$mapping->episode($episode['number']), $at];
                } elseif (is_int($season['number'] ?? null)) {
                    $episodes[] = [$season['number'], $episode['number'], $at];
                }
            }
        }

        return $episodes;
    }

    /**
     * What a show entry says about the show itself; nothing when the
     * episodes already say it all.
     *
     * @param  array<array-key, mixed>  $entry
     * @return Generator<int, MediaRow>
     */
    private function showRow(MediaSubject $subject, array $entry): Generator
    {
        $status = $entry['status'] ?? null;
        $row = new MediaRow(
            $subject,
            rating: $this->rating($entry),
            status: $status === 'hold' ? new ImportStatus(MediaUserStatus::OnHold, Payload::instant($entry['last_watched_at'] ?? null)) : null,
            drop: $status === 'dropped' ? new ImportDrop(Payload::instant($entry['last_watched_at'] ?? null)) : null,
            watchlist: $status === 'plantowatch' ? new ImportMark(Payload::instant($entry['added_to_watchlist_at'] ?? null)) : null,
        );

        if ($row->rating !== null || $row->status !== null || $row->drop !== null || $row->watchlist !== null) {
            yield $row;
        }
    }

    /**
     * @param  array<array-key, mixed>  $entry
     */
    private function filmRow(MediaSubject $subject, array $entry): MediaRow
    {
        $status = $entry['status'] ?? null;
        $watchedAt = Payload::instant($entry['last_watched_at'] ?? null);

        return new MediaRow(
            $subject,
            plays: $status === 'completed' ? [new ImportPlay($watchedAt)] : [],
            rating: $this->rating($entry),
            status: $status === 'hold' ? new ImportStatus(MediaUserStatus::OnHold, $watchedAt) : null,
            drop: $status === 'dropped' ? new ImportDrop($watchedAt) : null,
            watchlist: $status === 'plantowatch' ? new ImportMark(Payload::instant($entry['added_to_watchlist_at'] ?? null)) : null,
        );
    }

    /**
     * A movie or show placed at TMDB directly when Simkl holds its TMDB id,
     * else by IMDb/TVDB through the resolver.
     *
     * @param  array<array-key, mixed>  $object
     */
    private function rootSubject(string $kind, array $object, string $label): ?MediaSubject
    {
        $ids = $this->ids($object);

        if (isset($ids['tmdb'])) {
            return MediaSubject::direct($kind, 'tmdb', $ids['tmdb'], $label);
        }

        $foreign = ExternalIdSource::only($ids, ExternalIdSource::Imdb, ExternalIdSource::Tvdb);

        return $foreign === [] ? null : MediaSubject::external(MediaKind::from($kind), $foreign, $label);
    }

    /**
     * @param  array<array-key, mixed>  $entry
     */
    private function rating(array $entry): ?ImportRating
    {
        $value = $entry['user_rating'] ?? null;

        return is_int($value) && $value >= 1 && $value <= 10
            ? new ImportRating($value, Payload::instant($entry['user_rated_at'] ?? null))
            : null;
    }

    /**
     * Simkl's ids as strings (it sends some as numbers, some as strings).
     *
     * @param  array<array-key, mixed>  $object
     * @return array<string, string>
     */
    private function ids(array $object): array
    {
        return Payload::ids($object['ids'] ?? null);
    }

    /**
     * @param  array<array-key, mixed>  $entry
     * @return array<array-key, mixed>
     */
    private function object(array $entry, string $key): array
    {
        return is_array($entry[$key] ?? null) ? $entry[$key] : [];
    }

    /**
     * @param  array<array-key, mixed>  $object
     */
    private function label(array $object): string
    {
        $title = is_string($object['title'] ?? null) && $object['title'] !== '' ? $object['title'] : 'Simkl item';

        return is_int($object['year'] ?? null) ? "{$title} ({$object['year']})" : $title;
    }
}

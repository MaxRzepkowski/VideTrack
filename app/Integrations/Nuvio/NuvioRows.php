<?php

namespace App\Integrations\Nuvio;

use App\Catalog\ExternalIdSource;
use App\Catalog\MediaKind;
use App\Integrations\Anime\AnimeIdMap;
use App\Integrations\Anime\AnimeListRefused;
use App\Integrations\Anime\AnimeListUnavailable;
use App\Integrations\Anime\AnimeMapping;
use App\Integrations\Anime\KitsuClient;
use App\Integrations\Anime\ListMedium;
use App\Integrations\EpisodeLabel;
use App\Integrations\Import\Data\ImportPlay;
use App\Integrations\Import\Data\MediaSubject;
use App\Integrations\Import\Rows\MediaRow;
use App\Integrations\Import\Rows\SkipRow;
use App\Library\RecordKind;
use App\Support\Payload;
use App\Support\ServiceBusy;

/**
 * One entry of a Nuvio history as the import's row. Nuvio names a title by
 * what the add-on that played it uses: an IMDb id (Cinemeta), `tmdb:N`, or
 * `kitsu:N` from an anime add-on, which Kitsu's mappings turn into a
 * MyAnimeList entry and the anime id map into a TVDB season. Episodes keep
 * the add-on's numbering; anything else Nuvio names is reported by its id.
 */
class NuvioRows
{
    /** @var array<int, AnimeMapping|SkipRow> a Kitsu title's place, looked up once a run */
    private array $kitsu = [];

    public function __construct(private readonly AnimeIdMap $anime, private readonly KitsuClient $kitsuClient) {}

    /**
     * Null for what is not a viewing to import: a show's summary line without
     * its season and episode, which Nuvio's own clients write, or an entry
     * naming nothing.
     *
     * @param  array<array-key, mixed>  $item
     *
     * @throws AnimeListUnavailable|ServiceBusy
     */
    public function row(array $item): MediaRow|SkipRow|null
    {
        $id = Payload::string($item, 'content_id');

        if ($id === null) {
            return null;
        }

        $title = Payload::string($item, 'title') ?? $id;
        // Nuvio records a viewing again each time playback passes its end, so a
        // few seeks there are one viewing: weighed as a media server's log is.
        $plays = [new ImportPlay(Payload::epochMilliseconds($item, 'watched_at'), record: RecordKind::PlaybackLog)];

        $season = Payload::int($item, 'season');
        $episode = Payload::int($item, 'episode');

        return match (Payload::enum($item, 'content_type', NuvioContentType::class)) {
            NuvioContentType::Movie => $this->film($id, $title, $plays),
            NuvioContentType::Series => $season === null || $episode === null ? null : $this->episode($id, EpisodeLabel::of($title, $season, $episode), $season, $episode, $plays),
            null => $this->otherKind($item, $title),
        };
    }

    /**
     * An add-on's own kind (a channel, live TV): nothing this instance tracks,
     * said by its name; an entry with no kind at all names nothing.
     *
     * @param  array<array-key, mixed>  $item
     */
    private function otherKind(array $item, string $title): ?SkipRow
    {
        $kind = Payload::string($item, 'content_type');

        return $kind === null ? null : new SkipRow('import.skip_nuvio_unknown_kind', ['label' => $title, 'kind' => $kind]);
    }

    /**
     * @param  list<ImportPlay>  $plays
     *
     * @throws AnimeListUnavailable|ServiceBusy
     */
    private function film(string $id, string $label, array $plays): MediaRow|SkipRow
    {
        return match (true) {
            preg_match('/^tt\d+$/', $id) === 1 => new MediaRow(MediaSubject::external(MediaKind::Movie, [ExternalIdSource::Imdb->value => $id], $label), plays: $plays),
            preg_match('/^tmdb:(\d+)$/', $id, $tmdb) === 1 => new MediaRow(MediaSubject::direct('movie', 'tmdb', $tmdb[1], $label), plays: $plays),
            preg_match('/^kitsu:(\d+)$/', $id, $kitsu) === 1 => $this->anime((int) $kitsu[1], $label, null, $plays),
            default => new SkipRow('import.skip_nuvio_unknown_id', ['label' => $label, 'id' => $id]),
        };
    }

    /**
     * @param  list<ImportPlay>  $plays
     *
     * @throws AnimeListUnavailable|ServiceBusy
     */
    private function episode(string $id, string $label, int $season, int $episode, array $plays): MediaRow|SkipRow
    {
        return match (true) {
            preg_match('/^tt\d+$/', $id) === 1 => new MediaRow(MediaSubject::external(MediaKind::Episode, [], $label, [ExternalIdSource::Imdb->value => $id], $season, $episode), plays: $plays),
            preg_match('/^tmdb:(\d+)$/', $id, $tmdb) === 1 => new MediaRow(MediaSubject::below('show', 'tmdb', $tmdb[1], [$season, $episode], $label), plays: $plays),
            // An anime add-on numbers the episodes of one Kitsu entry; its season says nothing more.
            preg_match('/^kitsu:(\d+)$/', $id, $kitsu) === 1 => $this->anime((int) $kitsu[1], $label, $episode, $plays),
            default => new SkipRow('import.skip_nuvio_unknown_id', ['label' => $label, 'id' => $id]),
        };
    }

    /**
     * An anime by its Kitsu id: an episode of the TVDB season the entry is
     * part of, or a film or special by its own IMDb id.
     *
     * @param  list<ImportPlay>  $plays
     *
     * @throws AnimeListUnavailable|ServiceBusy
     */
    private function anime(int $kitsuId, string $label, ?int $episode, array $plays): MediaRow|SkipRow
    {
        $place = $this->kitsu[$kitsuId] ??= $this->place($kitsuId);

        if ($place instanceof SkipRow) {
            return new SkipRow($place->key, ['label' => $label]);
        }

        if ($episode !== null && $place->isSeason()) {
            [$tvdbSeason, $tvdbEpisode] = $place->episode($episode);

            return new MediaRow(
                MediaSubject::external(MediaKind::Episode, [], $label, [ExternalIdSource::Tvdb->value => (string) $place->tvdbId], $tvdbSeason, $tvdbEpisode),
                plays: $plays,
            );
        }

        // A film or a special: by its own IMDb id, as an anime list places it.
        $imdb = $place->standaloneImdb();

        return $imdb !== null
            ? new MediaRow(MediaSubject::external(MediaKind::Movie, [ExternalIdSource::Imdb->value => $imdb], $label), plays: $plays)
            : new SkipRow('import.skip_anime_special_without_id', ['label' => $label]);
    }

    /**
     * Where a Kitsu title sits, or why it cannot be placed (the skip's label
     * is filled in per entry).
     *
     * @throws AnimeListUnavailable|ServiceBusy
     */
    private function place(int $kitsuId): AnimeMapping|SkipRow
    {
        try {
            $document = $this->kitsuClient->anime($kitsuId);
        } catch (AnimeListRefused) {
            // One title Kitsu will not show: the rest of the history still comes in.
            return new SkipRow('import.skip_kitsu_refused_this_title');
        }

        if ($document === null) {
            return new SkipRow('import.skip_kitsu_knows_no_such_title');
        }

        $malId = $document->malId(Payload::map($document->page['data'] ?? null), ListMedium::Anime);

        if ($malId === null) {
            return new SkipRow('import.skip_kitsu_no_mal_id');
        }

        // Skipped, the viewing would be gone for good: the feed is not read
        // behind its position again. Failing, the run keeps the position.
        if (! $this->anime->isAvailable()) {
            throw new AnimeListUnavailable('import.the_anime_id_map_could_not_be_loaded');
        }

        // The label is the entry's, filled in where the skip is reported.
        return $this->anime->place($malId, '');
    }
}

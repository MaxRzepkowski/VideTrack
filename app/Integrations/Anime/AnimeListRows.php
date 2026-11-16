<?php

namespace App\Integrations\Anime;

use App\Catalog\ExternalIdSource;
use App\Catalog\MediaKind;
use App\Enums\MediaUserStatus;
use App\Integrations\Export\ListStatus;
use App\Integrations\Import\Data\ImportDrop;
use App\Integrations\Import\Data\ImportMark;
use App\Integrations\Import\Data\ImportPlay;
use App\Integrations\Import\Data\ImportRating;
use App\Integrations\Import\Data\ImportStatus;
use App\Integrations\Import\Data\MediaSubject;
use App\Integrations\Import\Rows\EpisodeRunRow;
use App\Integrations\Import\Rows\ImportRow;
use App\Integrations\Import\Rows\MediaRow;
use App\Integrations\Import\Rows\SkipRow;
use App\Library\PlayDating;
use Carbon\CarbonImmutable;

/**
 * An anime/manga list entry, translated into our rows. Shared by the MAL,
 * AniList and Kitsu sources — they differ in how they fetch, not in what an
 * entry means.
 *
 * Manga is ours already: its MAL id is the manga module's ref.
 *
 * Anime goes through the Kometa map. A MAL entry that is a season of a TVDB
 * series has its episodes ticked — all of them when completed, the first N
 * when watched that far (the trackers keep no
 * per-episode dates, so every episode of the entry gets one date). Rating,
 * on hold, dropped and planned land on the show. A film or special goes by
 * its own IMDb id and behaves like any film.
 */
final readonly class AnimeListRows
{
    public function __construct(private AnimeIdMap $map) {}

    /**
     * @return list<ImportRow>
     */
    public function for(AnimeListEntry $entry): array
    {
        return $entry->isAnime() ? $this->anime($entry) : [$this->manga($entry)];
    }

    private function label(AnimeListEntry $entry): string
    {
        return AnimeListEntry::label($entry->title, $entry->malId);
    }

    private function manga(AnimeListEntry $entry): MediaRow
    {
        $progress = $entry->progress > 0 ? $entry->progress : null;

        return new MediaRow(
            MediaSubject::direct('manga', 'mal', (string) $entry->malId, $this->label($entry)),
            plays: $entry->status === ListStatus::Completed
                ? [$this->finished($entry, $entry->total ?? $progress)]
                : [],
            rating: $this->rating($entry),
            status: match ($entry->status) {
                ListStatus::Current => new ImportStatus(MediaUserStatus::InProgress, $entry->updatedAt, $progress),
                ListStatus::OnHold => new ImportStatus(MediaUserStatus::OnHold, $entry->updatedAt, $progress),
                default => null,
            },
            drop: $entry->status === ListStatus::Dropped ? new ImportDrop($entry->updatedAt, $progress) : null,
            watchlist: $entry->status === ListStatus::Planned ? new ImportMark($entry->updatedAt) : null,
        );
    }

    /**
     * @return list<ImportRow>
     */
    private function anime(AnimeListEntry $entry): array
    {
        $label = $this->label($entry);

        if (! $this->map->isAvailable()) {
            return [new SkipRow('import.skip_anime_map_unavailable', ['label' => $label])];
        }

        $mapping = $this->map->forMal($entry->malId);

        if ($mapping === null) {
            return [new SkipRow('import.skip_anime_not_in_map', ['label' => $label])];
        }

        if ($mapping->isSeason()) {
            return $this->season($entry, $mapping, $label);
        }

        $imdb = $mapping->standaloneImdb();

        if ($imdb === null) {
            return [new SkipRow('import.skip_anime_special_without_id', ['label' => $label])];
        }

        return [$this->film($entry, $imdb, $label)];
    }

    /**
     * @return list<ImportRow>
     */
    private function season(AnimeListEntry $entry, AnimeMapping $mapping, string $label): array
    {
        $series = [ExternalIdSource::Tvdb->value => (string) $mapping->tvdbId];
        $rows = [];

        $watched = $entry->status === ListStatus::Completed
            ? max($entry->total ?? 0, $entry->progress)
            : $entry->progress;

        if ($watched > 0) {
            [$at, $dating] = $this->episodeDate($entry);
            $rows[] = new EpisodeRunRow(
                $label,
                $series,
                (int) $mapping->tvdbSeason,
                $mapping->episodeOffset + 1,
                $mapping->episodeOffset + $watched,
                $at,
                $dating,
            );
        }

        $show = new MediaRow(MediaSubject::external(MediaKind::Show, $series, $label), ...$this->beyondViewings($entry));

        if ($show->rating !== null || $show->status !== null || $show->drop !== null || $show->watchlist !== null) {
            $rows[] = $show;
        }

        return $rows;
    }

    /**
     * Completed: the day it was finished. Otherwise the day it was started —
     * and never on or after the day it was dropped, or the drop would read
     * as older than the viewing and not stand. A list that kept neither day
     * has only its last update.
     *
     * @return array{CarbonImmutable|null, PlayDating}
     */
    private function episodeDate(AnimeListEntry $entry): array
    {
        if ($entry->status === ListStatus::Completed && $entry->finishedAt !== null) {
            return [$entry->finishedAt, PlayDating::Viewing];
        }

        if ($entry->status !== ListStatus::Completed && $entry->startedAt !== null) {
            return [$entry->startedAt, PlayDating::Viewing];
        }

        return [
            $entry->status === ListStatus::Dropped ? $entry->updatedAt?->subMinute() : $entry->updatedAt,
            PlayDating::Activity,
        ];
    }

    /**
     * The completed viewing, on the day the list says it ended; a list that
     * kept no such day has only its last update, which moves with every edit.
     */
    private function finished(AnimeListEntry $entry, ?int $amount = null): ImportPlay
    {
        return $entry->finishedAt !== null
            ? new ImportPlay($entry->finishedAt, amount: $amount)
            : new ImportPlay($entry->updatedAt, amount: $amount, dating: PlayDating::Activity);
    }

    private function film(AnimeListEntry $entry, string $imdb, string $label): MediaRow
    {
        return new MediaRow(MediaSubject::external(MediaKind::Movie, [ExternalIdSource::Imdb->value => $imdb], $label), ...[
            'plays' => $entry->status === ListStatus::Completed ? [$this->finished($entry)] : [],
            ...$this->beyondViewings($entry),
        ]);
    }

    /**
     * What an anime entry says beyond its viewings, each dated by the
     * entry's last change: the rating, a pause, a drop, the watchlist. A
     * show or film being watched says so by its viewings alone.
     *
     * @return array{rating: ImportRating|null, status: ImportStatus|null, drop: ImportDrop|null, watchlist: ImportMark|null}
     */
    private function beyondViewings(AnimeListEntry $entry): array
    {
        return [
            'rating' => $this->rating($entry),
            'status' => $entry->status === ListStatus::OnHold ? new ImportStatus(MediaUserStatus::OnHold, $entry->updatedAt) : null,
            'drop' => $entry->status === ListStatus::Dropped ? new ImportDrop($entry->updatedAt) : null,
            'watchlist' => $entry->status === ListStatus::Planned ? new ImportMark($entry->updatedAt) : null,
        ];
    }

    private function rating(AnimeListEntry $entry): ?ImportRating
    {
        return $entry->score === null ? null : new ImportRating($entry->score, $entry->updatedAt);
    }
}

<?php

namespace App\Integrations\TvTime;

use App\Catalog\ExternalIdSource;
use App\Catalog\MediaKind;
use App\Integrations\EpisodeLabel;
use App\Integrations\Import\Contracts\ExplainsSetup;
use App\Integrations\Import\Contracts\ReadsFile;
use App\Integrations\Import\Data\ImportInput;
use App\Integrations\Import\Data\ImportManifest;
use App\Integrations\Import\Data\ImportMark;
use App\Integrations\Import\Data\ImportPlay;
use App\Integrations\Import\Data\ImportSelection;
use App\Integrations\Import\Data\MediaSubject;
use App\Integrations\Import\Data\SetupNote;
use App\Integrations\Import\ImportCategory;
use App\Integrations\Import\Rows\MediaRow;
use App\Integrations\Import\Rows\SkipRow;
use App\Support\Payload;
use Carbon\CarbonImmutable;
use Generator;

/**
 * A TV Time GDPR data package ({@see TvTimeArchive}).
 *
 * Episodes are placed by their TVDB ids — the episode's own, else its
 * show's and its numbers — never by name; films by title and year
 * ({@see TvTimeFilms}). A show the user added waits on the backlog unless
 * they watched some of it; a film saved for later does too. Ratings are
 * the films' (TV Time's episode votes have no counterpart here).
 */
final readonly class TvTimeZipSource implements ExplainsSetup, ReadsFile
{
    public const CODE = 'tvtime';

    private const EPISODE_PLAYS = ['watch-episode-', 'rewatch-episode-'];

    private const ADDED_SHOW = 'user-series-';

    /** How the older table names an episode viewing: its entity, and the type of the row. */
    private const NAMED_EPISODE = 'episode';

    private const NAMED_PLAY = 'watch';

    private const TRUE = 'true';

    /** followed_tv_show.csv spells its flags as numbers. */
    private const ARCHIVED = '1';

    public function __construct(private TvTimeFilms $films) {}

    public function code(): string
    {
        return self::CODE;
    }

    public function label(): string
    {
        return __('import.tv_time_data_package_zip');
    }

    public function description(): string
    {
        return __('import.episodes_and_films_you_watched_on');
    }

    public function taskLabel(): string
    {
        return __('import.import_from_tv_time');
    }

    public function accept(): string
    {
        return '.zip';
    }

    public function notes(): array
    {
        return [
            new SetupNote(__('import.tv_time_closed_in_july_2026')),
            new SetupNote(__('import.tv_time_films_are_found_by')),
        ];
    }

    public function analyze(ImportInput $input): ImportManifest
    {
        $archive = $this->archive($input);

        if (! $archive->isValid()) {
            return ImportManifest::invalid(__('import.the_file_is_not_a_tv_time'));
        }

        return ImportManifest::selectable([
            ImportCategory::History->counted(iterator_count($this->history($archive))),
            ImportCategory::Watchlist->counted(iterator_count($this->watchlist($archive))),
            ImportCategory::Ratings->counted(iterator_count($this->films->ratings($archive))),
        ]);
    }

    public function rows(ImportInput $input, ImportSelection $selection): Generator
    {
        $archive = $this->archive($input);

        if ($selection->has(ImportCategory::History->value)) {
            yield from $this->history($archive);
        }

        if ($selection->has(ImportCategory::Watchlist->value)) {
            yield from $this->watchlist($archive);
        }

        if ($selection->has(ImportCategory::Ratings->value)) {
            yield from $this->films->ratings($archive);
        }
    }

    private function archive(ImportInput $input): TvTimeArchive
    {
        return new TvTimeArchive((string) $input->path);
    }

    /**
     * Every episode and film viewing. The episodes of a v2 package are its
     * v2 rows; the older table only speaks for episodes where v2 is absent,
     * as both list the same viewings.
     *
     * @return Generator<int, MediaRow|SkipRow>
     */
    private function history(TvTimeArchive $archive): Generator
    {
        $keyed = false;

        foreach ($archive->rows(TvTimeTracking::Keyed) as $row) {
            if ($this->isEpisodePlay($row)) {
                $keyed = true;

                yield $this->keyedEpisode($row);
            }
        }

        foreach ($archive->rows(TvTimeTracking::Named) as $row) {
            if (! $keyed && Payload::text($row, 'entity_type') === self::NAMED_EPISODE && Payload::text($row, 'type') === self::NAMED_PLAY) {
                yield $this->namedEpisode($row);
            }
        }

        yield from $this->films->plays($archive);
    }

    /**
     * Shows added and not yet watched, or saved for later, and films saved
     * for later. A show the user archived is one they gave up on, not one
     * they want to see.
     *
     * @return Generator<int, MediaRow|SkipRow>
     */
    private function watchlist(TvTimeArchive $archive): Generator
    {
        $watched = [];

        foreach ($archive->rows(TvTimeTracking::Keyed) as $row) {
            if ($this->isEpisodePlay($row) && ($show = Payload::positiveInt($row, 's_id')) !== null) {
                $watched[$show] = true;
            }
        }

        $listed = [];

        foreach ($archive->rows(TvTimeTracking::Keyed) as $row) {
            $show = Payload::positiveInt($row, 's_id');

            if (! str_starts_with($row['key'], self::ADDED_SHOW) || $show === null || isset($listed[$show])
                || Payload::text($row, 'is_archived') === self::TRUE) {
                continue;
            }

            if (Payload::text($row, 'is_for_later') === self::TRUE || (Payload::text($row, 'is_followed') === self::TRUE && ! isset($watched[$show]))) {
                $listed[$show] = true;

                yield $this->laterShow($show, Payload::text($row, 'series_name'), Payload::instant(Payload::text($row, 'created_at')));
            }
        }

        // Follows older than the v2 flags.
        foreach ($archive->followed() as $row) {
            $show = Payload::positiveInt($row, 'tv_show_id');

            if ($show === null || isset($watched[$show]) || isset($listed[$show]) || Payload::text($row, 'archived') === self::ARCHIVED) {
                continue;
            }

            $listed[$show] = true;

            yield $this->laterShow($show, Payload::text($row, 'tv_show_name'), Payload::instant(Payload::text($row, 'created_at')));
        }

        yield from $this->films->savedForLater($archive);
    }

    /**
     * @param  array<string, string>  $row
     */
    private function isEpisodePlay(array $row): bool
    {
        foreach (self::EPISODE_PLAYS as $prefix) {
            if (str_starts_with($row['key'], $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * A v2 viewing. Its numbers sit in `s_no`/`ep_no`; some packages leave
     * those blank and fill the older `season_number`/`episode_number`.
     *
     * @param  array<string, string>  $row
     */
    private function keyedEpisode(array $row): MediaRow|SkipRow
    {
        $episode = Payload::positiveInt($row, 'ep_no');
        [$season, $episode] = $episode !== null
            ? [Payload::int($row, 's_no'), $episode]
            : [Payload::int($row, 'season_number') ?? Payload::int($row, 's_no'), Payload::int($row, 'episode_number')];

        return $this->episode(
            Payload::text($row, 'series_name'),
            Payload::positiveInt($row, 'ep_id') ?? Payload::positiveInt($row, 'episode_id'),
            Payload::positiveInt($row, 's_id'),
            $season,
            $episode,
            Payload::instant(Payload::text($row, 'created_at')),
        );
    }

    /**
     * An episode viewing of a package older than v2: the episode's TVDB id
     * alone names it.
     *
     * @param  array<string, string>  $row
     */
    private function namedEpisode(array $row): MediaRow|SkipRow
    {
        return $this->episode(
            Payload::text($row, 'series_name'),
            Payload::positiveInt($row, 'episode_id'),
            null,
            Payload::int($row, 'season_number'),
            Payload::int($row, 'episode_number'),
            TvTimeArchive::watchedAt($row),
        );
    }

    private function episode(string $series, ?int $episodeId, ?int $showId, ?int $season, ?int $number, ?CarbonImmutable $at): MediaRow|SkipRow
    {
        $label = EpisodeLabel::of($series, $season, $number);
        $byNumbers = $showId !== null && $season !== null && $number !== null;

        if ($episodeId === null && ! $byNumbers) {
            return new SkipRow('import.skip_tvtime_episode_without_ids', ['label' => $label === '' ? __('import.unnamed_episode') : $label]);
        }

        return new MediaRow(
            MediaSubject::external(
                MediaKind::Episode,
                $episodeId === null ? [] : [ExternalIdSource::Tvdb->value => (string) $episodeId],
                $label,
                $byNumbers ? [ExternalIdSource::Tvdb->value => (string) $showId] : [],
                $season,
                $number,
            ),
            plays: [new ImportPlay($at)],
        );
    }

    private function laterShow(int $show, string $name, ?CarbonImmutable $at): MediaRow
    {
        return new MediaRow(
            MediaSubject::external(MediaKind::Show, [ExternalIdSource::Tvdb->value => (string) $show], $name === '' ? __('import.tvdb_show', ['id' => (string) $show]) : $name),
            watchlist: new ImportMark($at),
        );
    }
}

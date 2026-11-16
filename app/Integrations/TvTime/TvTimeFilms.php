<?php

namespace App\Integrations\TvTime;

use App\Catalog\MediaKind;
use App\Integrations\Import\Data\ImportMark;
use App\Integrations\Import\Data\ImportPlay;
use App\Integrations\Import\Data\ImportRating;
use App\Integrations\Import\Data\MediaSubject;
use App\Integrations\Import\Data\TitleQuery;
use App\Integrations\Import\Rows\MediaRow;
use App\Integrations\Import\Rows\SkipRow;
use App\Library\RatingScale;
use App\Support\Payload;
use Generator;

/**
 * The films of a TV Time package. They live in the older tracking table
 * only, with no id of any catalogue: each is searched by the English title
 * TV Time sorts it by and the year it was released. Their ratings name
 * them by TV Time's uuid alone, which that table spells out.
 */
final readonly class TvTimeFilms
{
    private const ENTITY = 'movie';

    private const PLAYS = ['watch', 'rewatch'];

    private const LATER = 'towatch';

    /** TV Time's star set (bad, ok, good, great, wow) by the id a vote ends with: one to five stars. */
    private const VOTE_STARS = [1 => 1, 27 => 2, 28 => 3, 29 => 4, 3 => 5];

    /** The year of the first film; TV Time writes Go's zero time (year 1) for a date it never had. */
    private const FIRST_FILM_YEAR = 1888;

    /**
     * Every viewing, rewatches included.
     *
     * @return Generator<int, MediaRow|SkipRow>
     */
    public function plays(TvTimeArchive $archive): Generator
    {
        foreach ($archive->rows(TvTimeTracking::Named) as $row) {
            if ($this->is($row, ...self::PLAYS)) {
                yield $this->film($row, fn (MediaSubject $subject): MediaRow => new MediaRow($subject, plays: [new ImportPlay(TvTimeArchive::watchedAt($row))]));
            }
        }
    }

    /**
     * The films saved for later.
     *
     * @return Generator<int, MediaRow|SkipRow>
     */
    public function savedForLater(TvTimeArchive $archive): Generator
    {
        foreach ($archive->rows(TvTimeTracking::Named) as $row) {
            if ($this->is($row, self::LATER)) {
                yield $this->film($row, fn (MediaSubject $subject): MediaRow => new MediaRow($subject, watchlist: new ImportMark(Payload::instant(Payload::text($row, 'created_at')))));
            }
        }
    }

    /**
     * The last vote per film. A vote on an episode has no counterpart here
     * and is passed over.
     *
     * @return Generator<int, MediaRow|SkipRow>
     */
    public function ratings(TvTimeArchive $archive): Generator
    {
        $films = [];

        foreach ($archive->rows(TvTimeTracking::Named) as $row) {
            if (Payload::text($row, 'entity_type') === self::ENTITY && Payload::text($row, 'uuid') !== '') {
                $films[Payload::text($row, 'uuid')] = $row;
            }
        }

        $votes = [];

        foreach ($archive->votes() as $row) {
            $episode = Payload::text($row, 'episode_id');

            if (Payload::text($row, 'uuid') !== '' && ($episode === '' || $episode === '0')) {
                $votes[Payload::text($row, 'uuid')] = $row;
            }
        }

        foreach ($votes as $uuid => $vote) {
            $parts = explode('-', $vote['vote_key']);
            $stars = self::VOTE_STARS[(int) end($parts)] ?? null;
            $film = $films[$uuid] ?? ['movie_name' => Payload::text($vote, 'movie_name')];

            yield $stars === null
                ? new SkipRow('import.skip_tvtime_unknown_vote', ['label' => $this->name($film)])
                : $this->film($film, fn (MediaSubject $subject): MediaRow => new MediaRow($subject, rating: new ImportRating(RatingScale::fromStars($stars))));
        }
    }

    /**
     * @param  array<string, string>  $row
     */
    private function is(array $row, string ...$types): bool
    {
        return Payload::text($row, 'entity_type') === self::ENTITY && in_array(Payload::text($row, 'type'), $types, true);
    }

    /**
     * A film placed by its English title and year, handed to $build; a skip
     * when no title survives.
     *
     * @param  array<string, string>  $row
     * @param  callable(MediaSubject): MediaRow  $build
     */
    private function film(array $row, callable $build): MediaRow|SkipRow
    {
        $title = self::englishTitle($row) ?? Payload::text($row, 'movie_name');
        $year = $this->releaseYear($row);
        $name = $this->name($row);

        if ($title === '') {
            return new SkipRow('import.skip_tvtime_film_without_title');
        }

        return $build(MediaSubject::external(
            MediaKind::Movie,
            [],
            $year === null ? $name : "{$name} ({$year})",
            byTitle: new TitleQuery($title, $year),
        ));
    }

    /**
     * TV Time names a film in the user's language (`movie_name`) but sorts
     * it by its English title, slugged into `alpha_range_key`
     * ("watch-alpha-space-sweepers"); the English one finds the film.
     *
     * @param  array<string, string>  $row
     */
    public static function englishTitle(array $row): ?string
    {
        $type = Payload::text($row, 'type');
        $prefix = "{$type}-alpha-";
        $key = Payload::text($row, 'alpha_range_key');

        if ($type === '' || ! str_starts_with($key, $prefix)) {
            return null;
        }

        $title = trim(str_replace('-', ' ', substr($key, strlen($prefix))));

        return $title === '' ? null : $title;
    }

    /**
     * How the film reads in the report: the name the user saw.
     *
     * @param  array<string, string>  $row
     */
    private function name(array $row): string
    {
        return Payload::string($row, 'movie_name') ?? self::englishTitle($row) ?? __('import.unnamed_film');
    }

    /**
     * @param  array<string, string>  $row
     */
    private function releaseYear(array $row): ?int
    {
        $year = Payload::yearOf(Payload::string($row, 'release_date'));

        return $year !== null && $year >= self::FIRST_FILM_YEAR ? $year : null;
    }
}

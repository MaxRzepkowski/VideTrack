<?php

namespace App\Integrations\WeTrakr;

use App\Catalog\MediaKind;
use App\Integrations\Import\CsvStream;
use App\Integrations\Import\Rows\SkipRow;
use App\Integrations\Import\ZipExport;
use App\Support\Payload;
use Carbon\CarbonImmutable;
use Carbon\Exceptions\InvalidFormatException;
use Generator;

/**
 * Read-only view over a WeTrakr export: a ZIP of flat CSV files, each of
 * them optional (the user switches them off before exporting) and a large
 * one split in parts (`tracklog_1.csv`, `tracklog_2.csv`).
 *
 * An episode row carries the episode's own TMDB id and names its show only
 * by `show_title`; the show's id is in a row about the show itself, when
 * the export has one. An episode on a list carries no show and no numbers
 * at all, only its id, so it is found through the rows that name it.
 */
final class WeTrakrArchive
{
    /** The columns every file of each section has, past which a file is not WeTrakr's. */
    private const COLUMNS = [
        WeTrakrSection::Tracklog->value => ['title', 'type', 'tmdb_id', 'status', 'tracked_at'],
        WeTrakrSection::Ratings->value => ['title', 'type', 'tmdb_id', 'rating', 'rated_at'],
        WeTrakrSection::Lists->value => ['list_name', 'title', 'type', 'tmdb_id', 'rank'],
        WeTrakrSection::Favorites->value => ['title', 'type', 'tmdb_id', 'created_at'],
    ];

    private readonly ZipExport $zip;

    /** @var array<string, string|null>|null a show's name, folded, => its TMDB id; null for a name two shows share */
    private ?array $shows = null;

    /** @var array<string, array{string, int, int}>|null an episode's TMDB id => its show's name, season and number */
    private ?array $episodes = null;

    public function __construct(string $path)
    {
        $this->zip = new ZipExport($path);
    }

    public function isValid(): bool
    {
        foreach (WeTrakrSection::cases() as $section) {
            if ($this->files($section) !== []) {
                return true;
            }
        }

        return false;
    }

    /**
     * Every row of one section, across its parts; a row the CSV cannot read
     * is left out here and named once by malformed().
     *
     * @return Generator<int, array<string, string>>
     */
    public function rows(WeTrakrSection $section): Generator
    {
        foreach ($this->files($section) as $csv) {
            yield from $csv->rows();
        }
    }

    /**
     * The rows of every section the CSV cannot read, each as the report names it.
     *
     * @return Generator<int, SkipRow>
     */
    public function malformed(): Generator
    {
        foreach (WeTrakrSection::cases() as $section) {
            foreach ($this->files($section) as $csv) {
                foreach ($csv->entries() as $entry) {
                    if ($entry instanceof SkipRow) {
                        yield $entry;
                    }
                }
            }
        }
    }

    /** The show's TMDB id, as a row about the show gives it; null when none does or two shows go by that name. */
    public function showId(string $title): ?string
    {
        if ($this->shows === null) {
            $this->shows = [];

            foreach (WeTrakrSection::cases() as $section) {
                foreach ($this->rows($section) as $row) {
                    $id = Payload::id($row['tmdb_id']);
                    $name = mb_strtolower(Payload::text($row, 'title'));

                    if (self::kind($row) !== MediaKind::Show || $id === null || $name === '') {
                        continue;
                    }

                    $known = array_key_exists($name, $this->shows) ? $this->shows[$name] : $id;
                    $this->shows[$name] = $known === $id ? $id : null;
                }
            }
        }

        return $this->shows[mb_strtolower($title)] ?? null;
    }

    /**
     * Where an episode sits, by its own TMDB id, as a row that numbers it says.
     *
     * @return array{string, int, int}|null its show's name, season and number
     */
    public function episode(string $tmdbId): ?array
    {
        if ($this->episodes === null) {
            $this->episodes = [];

            foreach ([WeTrakrSection::Tracklog, WeTrakrSection::Ratings] as $section) {
                foreach ($this->rows($section) as $row) {
                    $place = self::place($row);
                    $id = Payload::id($row['tmdb_id']);

                    if ($place !== null && $id !== null) {
                        $this->episodes[$id] ??= $place;
                    }
                }
            }
        }

        return $this->episodes[$tmdbId] ?? null;
    }

    /**
     * An episode row's show, season and number; null when it lacks one of them.
     *
     * @param  array<string, string>  $row
     * @return array{string, int, int}|null
     */
    public static function place(array $row): ?array
    {
        $show = Payload::text($row, 'show_title');
        $season = Payload::int($row, 'season_number');
        $number = Payload::int($row, 'episode_number');

        return self::kind($row) !== MediaKind::Episode || $show === '' || $season === null || $number === null ? null : [$show, $season, $number];
    }

    /**
     * What a row is about; null for anything but a movie, a show, a season
     * or an episode, the four kinds WeTrakr tracks.
     *
     * @param  array<string, string>  $row
     */
    public static function kind(array $row): ?MediaKind
    {
        $kind = Payload::enum($row, 'type', MediaKind::class);

        return in_array($kind, [MediaKind::Movie, MediaKind::Show, MediaKind::Season, MediaKind::Episode], true) ? $kind : null;
    }

    /**
     * WeTrakr writes moments as JavaScript's `Date.toString()` does:
     * "Wed Mar 21 2018 03:42:00 GMT+0000 (Coordinated Universal Time)", the
     * month sometimes spelled out. The weekday is left out of the reading
     * (a wrong one would move the date), and a day the month does not have
     * is no date. Blank is a moment it does not know.
     */
    public static function date(string $value): ?CarbonImmutable
    {
        $text = trim(explode(' (', $value, 2)[0]);

        if (preg_match('/^\w+ (\w+ (\d{1,2}) \d{4} \d{2}:\d{2}:\d{2} GMT[+-]\d{4})$/', $text, $parts) !== 1) {
            return null;
        }

        foreach (['M d Y H:i:s \\G\\M\\TO', 'F j Y H:i:s \\G\\M\\TO'] as $format) {
            try {
                $date = CarbonImmutable::createFromFormat($format, $parts[1]);
            } catch (InvalidFormatException) {
                continue;
            }

            // The day as written, or the month overflowed into the next one.
            if ($date !== null && $date->day === (int) $parts[2]) {
                return $date->utc();
            }
        }

        return null;
    }

    /**
     * The section's files, wherever they lie and however many parts.
     *
     * @return list<CsvStream>
     */
    private function files(WeTrakrSection $section): array
    {
        $files = [];

        foreach ($this->zip->names() as $entry) {
            $name = mb_strtolower(basename($entry));

            if (! str_starts_with($name, $section->value) || ! str_ends_with($name, '.csv')) {
                continue;
            }

            $csv = $this->zip->csv($entry, self::COLUMNS[$section->value]);

            if ($csv->isValid()) {
                $files[] = $csv;
            }
        }

        return $files;
    }
}

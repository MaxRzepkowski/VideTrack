<?php

namespace App\Integrations\Grouvee;

use App\Catalog\MediaKind;
use App\Enums\MediaUserStatus;
use App\Integrations\Import\Contracts\ExplainsSetup;
use App\Integrations\Import\Contracts\ReadsFile;
use App\Integrations\Import\CsvStream;
use App\Integrations\Import\Data\ImportInput;
use App\Integrations\Import\Data\ImportListEntry;
use App\Integrations\Import\Data\ImportManifest;
use App\Integrations\Import\Data\ImportMark;
use App\Integrations\Import\Data\ImportPlay;
use App\Integrations\Import\Data\ImportRating;
use App\Integrations\Import\Data\ImportSelection;
use App\Integrations\Import\Data\ImportStatus;
use App\Integrations\Import\Data\ManifestCategory;
use App\Integrations\Import\Data\MediaSubject;
use App\Integrations\Import\Data\SetupNote;
use App\Integrations\Import\Data\TitleQuery;
use App\Integrations\Import\OwnLists;
use App\Integrations\Import\Playtime;
use App\Integrations\Import\Rows\ListRow;
use App\Integrations\Import\Rows\MediaRow;
use App\Integrations\Import\Rows\SkipRow;
use App\Library\PlayDating;
use App\Library\RatingScale;
use App\Support\Payload;
use Carbon\CarbonImmutable;
use Generator;

/**
 * A Grouvee collection exported as CSV (Settings → Export). One row per
 * game; its shelves, platforms and playthroughs are JSON inside the CSV.
 * "Played" is a finished play, one per playthrough with a finish date and
 * its time, else one dated when the game was shelved; "Playing" is in
 * progress; "Backlog" and "Wish List" the watchlist; a shelf of the user's
 * own a list to pick. Grouvee writes "None" for a missing date and 0 for a
 * time nobody logged.
 *
 * Grouvee takes its games from IGDB and exports IGDB's id, so a game is
 * placed by it; an older export without the column falls back to the name
 * and release year.
 */
final class GrouveeCsvSource implements ExplainsSetup, ReadsFile
{
    public const CODE = 'grouvee';

    /** The provider whose ids Grouvee exports, the one serving games here. */
    private const IGDB = 'igdb';

    public function code(): string
    {
        return self::CODE;
    }

    public function label(): string
    {
        return __('import.grouvee_export_csv');
    }

    public function description(): string
    {
        return __('import.games_you_played_are_playing_or_shelved');
    }

    public function taskLabel(): string
    {
        return __('import.import_from_grouvee');
    }

    public function accept(): string
    {
        return '.csv';
    }

    public function notes(): array
    {
        return [new SetupNote(__('import.on_grouvee_open_settings_and_export'), 'https://www.grouvee.com/export/')];
    }

    public function analyze(ImportInput $input): ImportManifest
    {
        $csv = $this->csv($input);

        if (! $csv->isValid()) {
            return ImportManifest::invalid(__('import.the_file_is_not_a_grouvee'));
        }

        $counts = array_fill_keys(array_column(GrouveeShelf::cases(), 'value'), 0);
        $own = new OwnLists;
        $unreadable = 0;

        foreach ($csv->entries() as $row) {
            if ($row instanceof SkipRow) {
                $unreadable++;

                continue;
            }

            foreach ($this->standsFor($row) as $shelf) {
                $counts[$shelf->value]++;
            }

            foreach (array_keys($this->ownShelves($row)) as $name) {
                $own->count((string) $name);
            }
        }

        return ImportManifest::selectable(
            array_map(fn (GrouveeShelf $shelf): ManifestCategory => new ManifestCategory($shelf->value, __($shelf->importLabel()), $counts[$shelf->value]), GrouveeShelf::cases()),
            $own->offered(),
            $unreadable,
        );
    }

    public function rows(ImportInput $input, ImportSelection $selection): Generator
    {
        $lists = OwnLists::picked($selection);

        foreach ($lists as $list) {
            yield new ListRow($list);
        }

        foreach ($this->csv($input)->entries() as $row) {
            if ($row instanceof SkipRow) {
                yield $row;

                continue;
            }

            $name = trim($row['name']);

            if ($name === '') {
                yield new SkipRow('import.skip_grouvee_no_name');

                continue;
            }

            $subject = $this->subject($row, $name);

            foreach ($this->standsFor($row) as $shelf) {
                if ($selection->has($shelf->value)) {
                    yield $this->shelved($row, $subject, $shelf);
                }
            }

            foreach ($this->ownShelves($row) as $shelf => $added) {
                $list = $lists[OwnLists::key((string) $shelf)] ?? null;

                if ($list !== null) {
                    yield new MediaRow($subject, lists: [new ImportListEntry($list, $added)]);
                }
            }
        }
    }

    private function csv(ImportInput $input): CsvStream
    {
        return new CsvStream((string) $input->path, ['name', 'shelves', 'dates']);
    }

    /**
     * @param  array<string, string>  $row
     */
    private function shelved(array $row, MediaSubject $subject, GrouveeShelf $shelf): MediaRow
    {
        $shelves = $this->shelves($row);
        $runs = $this->runs($row);

        return match ($shelf) {
            GrouveeShelf::Played => new MediaRow($subject, plays: $this->plays($runs, $shelves['played'] ?? null)),
            GrouveeShelf::Playing => new MediaRow($subject, status: new ImportStatus(
                MediaUserStatus::InProgress,
                $shelves['playing'] ?? null,
                $runs === [] ? null : end($runs)['minutes'],
            )),
            GrouveeShelf::Wanted => new MediaRow($subject, watchlist: new ImportMark($shelves['backlog'] ?? $shelves['wish list'] ?? null)),
            GrouveeShelf::Rated => new MediaRow($subject, rating: $this->rating($row)),
        };
    }

    /**
     * A play per playthrough with a finish date; without any, one play dated
     * by when the game went on the shelf.
     *
     * @param  list<array{started: CarbonImmutable|null, finished: CarbonImmutable|null, minutes: int|null}>  $runs
     * @return list<ImportPlay>
     */
    private function plays(array $runs, ?CarbonImmutable $shelved): array
    {
        $plays = [];

        foreach ($runs as $run) {
            if ($run['finished'] !== null) {
                $plays[] = new ImportPlay($run['finished'], amount: $run['minutes']);
            }
        }

        if ($plays !== []) {
            return $plays;
        }

        $minutes = array_filter(array_column($runs, 'minutes'));

        return [new ImportPlay($shelved, amount: $minutes === [] ? null : max($minutes), dating: PlayDating::Activity)];
    }

    /**
     * @param  array<string, string>  $row
     */
    private function subject(array $row, string $name): MediaSubject
    {
        $igdb = trim(Payload::text($row, 'igdb_id'));

        if (ctype_digit($igdb)) {
            return MediaSubject::direct(MediaKind::Game->value, self::IGDB, $igdb, $name);
        }

        return MediaSubject::external(MediaKind::Game, [], $name, byTitle: new TitleQuery($name, Payload::yearOf(Payload::text($row, 'release_date')), exactTitle: true));
    }

    /**
     * Every category the game falls in.
     *
     * @param  array<string, string>  $row
     * @return list<GrouveeShelf>
     */
    private function standsFor(array $row): array
    {
        $found = [];

        foreach (array_keys($this->shelves($row)) as $name) {
            $shelf = GrouveeShelf::SHELVES[$name] ?? null;

            if ($shelf !== null) {
                $found[$shelf->value] = $shelf;
            }
        }

        if ($this->rating($row) !== null) {
            $found[GrouveeShelf::Rated->value] = GrouveeShelf::Rated;
        }

        return array_values($found);
    }

    /**
     * The game's shelves, folded name => when it went on it.
     *
     * @param  array<string, string>  $row
     * @return array<string, CarbonImmutable|null>
     */
    private function shelves(array $row): array
    {
        $shelves = [];

        foreach ($this->json($row['shelves']) as $name => $shelf) {
            $shelves[mb_strtolower(trim((string) $name))] = Payload::instant(is_array($shelf) ? Payload::string($shelf, 'date_added') : null);
        }

        return $shelves;
    }

    /**
     * The user's own shelves, as they named them => when the game went on it.
     *
     * @param  array<string, string>  $row
     * @return array<string, CarbonImmutable|null>
     */
    private function ownShelves(array $row): array
    {
        $own = [];

        foreach ($this->json($row['shelves']) as $name => $shelf) {
            $name = trim((string) $name);

            if ($name !== '' && ! isset(GrouveeShelf::SHELVES[mb_strtolower($name)])) {
                $own[$name] = Payload::instant(is_array($shelf) ? Payload::string($shelf, 'date_added') : null);
            }
        }

        return $own;
    }

    /**
     * The game's playthroughs. Grouvee writes one with nothing in it when a
     * game is shelved; it is no playthrough.
     *
     * @param  array<string, string>  $row
     * @return list<array{started: CarbonImmutable|null, finished: CarbonImmutable|null, minutes: int|null}>
     */
    private function runs(array $row): array
    {
        $runs = [];

        foreach (Payload::list($this->json($row['dates'])) as $run) {
            $seconds = Payload::int($run, 'seconds_played');
            $read = [
                'started' => Payload::calendarDay($run['date_started'] ?? null),
                'finished' => Payload::calendarDay($run['date_finished'] ?? null),
                'minutes' => $seconds === null ? null : Playtime::ofSeconds($seconds),
            ];

            if ($read !== ['started' => null, 'finished' => null, 'minutes' => null]) {
                $runs[] = $read;
            }
        }

        return $runs;
    }

    /**
     * Grouvee's stars, 1–5.
     *
     * @param  array<string, string>  $row
     */
    private function rating(array $row): ?ImportRating
    {
        $stars = RatingScale::readStars(Payload::text($row, 'rating'));

        return $stars === null ? null : new ImportRating($stars);
    }

    /**
     * A column that holds JSON.
     *
     * @return array<array-key, mixed>
     */
    private function json(string $value): array
    {
        return Payload::map(json_decode($value, true));
    }
}

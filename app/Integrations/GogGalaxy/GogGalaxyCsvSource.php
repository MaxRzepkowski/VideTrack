<?php

namespace App\Integrations\GogGalaxy;

use App\Catalog\MediaKind;
use App\Enums\MediaUserStatus;
use App\Integrations\Import\Contracts\ExplainsSetup;
use App\Integrations\Import\Contracts\ReadsFile;
use App\Integrations\Import\CsvStream;
use App\Integrations\Import\Data\ImportInput;
use App\Integrations\Import\Data\ImportListEntry;
use App\Integrations\Import\Data\ImportManifest;
use App\Integrations\Import\Data\ImportRating;
use App\Integrations\Import\Data\ImportSelection;
use App\Integrations\Import\Data\ImportStatus;
use App\Integrations\Import\Data\ManifestCategory;
use App\Integrations\Import\Data\MediaSubject;
use App\Integrations\Import\Data\SetupNote;
use App\Integrations\Import\Data\TitleQuery;
use App\Integrations\Import\LibraryList;
use App\Integrations\Import\Playtime;
use App\Integrations\Import\Rows\ListRow;
use App\Integrations\Import\Rows\MediaRow;
use App\Integrations\Import\Rows\SkipRow;
use App\Library\RatingScale;
use App\Support\Payload;
use Generator;

/**
 * A GOG Galaxy 2.0 library as GOG-Galaxy-Export-Script saves it (Galaxy has
 * no export of its own): `gameDB.csv`, tab-separated by default, one row per
 * game across every store Galaxy links, DLCs left out.
 *
 * Galaxy knows what a user owns and how long they played, never whether a
 * game was finished, so nothing here is a finished play: every game goes on
 * a "GOG Galaxy" list, with the user's stars. A played game can come over as
 * in progress with its play time, only when the user asks, as play time
 * alone does not say the game is still being played. The script exports no
 * store ids, so games are found by name and release year.
 */
final class GogGalaxyCsvSource implements ExplainsSetup, ReadsFile
{
    public const CODE = 'gog-galaxy';

    /** The service the list of the games is named after. */
    private const SERVICE = 'GOG Galaxy';

    private const PLAYED = 'played';

    public function code(): string
    {
        return self::CODE;
    }

    public function label(): string
    {
        return __('import.gog_galaxy_library_csv');
    }

    public function description(): string
    {
        return __('import.games_in_your_gog_galaxy_library');
    }

    public function taskLabel(): string
    {
        return __('import.import_from_gog_galaxy');
    }

    public function accept(): string
    {
        return '.csv';
    }

    public function notes(): array
    {
        return [
            new SetupNote(__('import.gog_galaxy_has_no_export_of'), 'https://github.com/AB1908/GOG-Galaxy-Export-Script'),
            new SetupNote(__('import.gog_galaxy_games_are_found_by')),
        ];
    }

    public function analyze(ImportInput $input): ImportManifest
    {
        $csv = $this->csv($input);

        if (! $csv->isValid()) {
            return ImportManifest::invalid(__('import.the_file_is_not_a_gog_galaxy'));
        }

        $games = 0;
        $played = 0;
        $unreadable = 0;

        foreach ($csv->entries() as $row) {
            if ($row instanceof SkipRow) {
                $unreadable++;
            } elseif (! $this->hidden($row)) {
                $games++;
                $played += $this->minutes($row) === null ? 0 : 1;
            }
        }

        return ImportManifest::selectable([
            (new LibraryList(self::SERVICE))->category($games),
            (new ManifestCategory(self::PLAYED, __('import.gog_galaxy_played_games_in_progress'), $played))->optIn(),
        ], unreadable: $unreadable);
    }

    public function rows(ImportInput $input, ImportSelection $selection): Generator
    {
        $library = new LibraryList(self::SERVICE);
        $list = $library->ref();
        $listing = $library->isPicked($selection);
        $playing = $selection->has(self::PLAYED);

        if ($listing) {
            yield new ListRow($list);
        }

        foreach ($this->csv($input)->entries() as $row) {
            if ($row instanceof SkipRow) {
                yield $row;

                continue;
            }

            $minutes = $this->minutes($row);
            $inProgress = $playing && $minutes !== null;

            if ($this->hidden($row) || (! $listing && ! $inProgress)) {
                continue;
            }

            $title = trim($row['title']);

            if ($title === '') {
                yield new SkipRow('import.skip_gog_galaxy_no_title');

                continue;
            }

            $rating = $listing ? RatingScale::readStars(Payload::text($row, 'myRating')) : null;
            $lastPlayed = Payload::instant(Payload::text($row, 'lastPlayed'));

            yield new MediaRow(
                MediaSubject::external(MediaKind::Game, [], $title, byTitle: new TitleQuery($title, Payload::yearOf(Payload::text($row, 'releaseDate')), exactTitle: true)),
                rating: $rating === null ? null : new ImportRating($rating),
                status: $inProgress ? new ImportStatus(MediaUserStatus::InProgress, $lastPlayed, $minutes) : null,
                lists: $listing ? [new ImportListEntry($list)] : [],
            );
        }
    }

    private function csv(ImportInput $input): CsvStream
    {
        return new CsvStream((string) $input->path, ['title']);
    }

    /**
     * The total in minutes the script sums over every store a game is on.
     *
     * @param  array<string, string>  $row
     */
    private function minutes(array $row): ?int
    {
        return Playtime::minutes(Payload::text($row, 'gameMins'));
    }

    /**
     * @param  array<string, string>  $row
     */
    private function hidden(array $row): bool
    {
        return Payload::flag($row, 'isHidden');
    }
}

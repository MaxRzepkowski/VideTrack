<?php

namespace App\Integrations\Playnite;

use App\Catalog\ExternalIdSource;
use App\Catalog\MediaKind;
use App\Enums\MediaUserStatus;
use App\Integrations\Import\ColumnMap;
use App\Integrations\Import\Contracts\ExplainsSetup;
use App\Integrations\Import\Contracts\ReadsFile;
use App\Integrations\Import\CsvStream;
use App\Integrations\Import\Data\ImportDrop;
use App\Integrations\Import\Data\ImportInput;
use App\Integrations\Import\Data\ImportListEntry;
use App\Integrations\Import\Data\ImportListRef;
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
use App\Integrations\Import\LibraryList;
use App\Integrations\Import\Playtime;
use App\Integrations\Import\Rows\ListRow;
use App\Integrations\Import\Rows\MediaRow;
use App\Integrations\Import\Rows\SkipRow;
use App\Library\PlayDating;
use App\Library\RatingScale;
use App\Support\Payload;
use App\Support\RegionalDate;
use Generator;

/**
 * A Playnite library as the Library Exporter Advanced add-on saves it: one
 * row per game, with the columns the user ticked. Only the name is needed;
 * the rest is used where present.
 *
 * The header is written in the language of Playnite's interface, so columns
 * are read by their English and Polish names. Dates are written the way
 * Windows shows them in the user's region ("11/16/2026 3:04:05 PM",
 * "16.11.2026 15:04:05"), so only their day is read
 * ({@see RegionalDate::day()}). The play time is a total in seconds: a finished
 * game gets one play with all of it, dated by the last launch, as a
 * launcher knows no sessions.
 *
 * Each completion status the file holds is a category of its own, labelled
 * with what it will do, so the user sees before importing what their own
 * statuses mean here. A Steam game is placed by its app id; any other by its
 * name and year.
 */
final class PlayniteCsvSource implements ExplainsSetup, ReadsFile
{
    public const CODE = 'playnite';

    private const COLUMNS = [
        'name' => ['Name', 'Nazwa'],
        'status' => ['Completion Status', 'Stopień ukończenia'],
        'playtime' => ['Time Played', 'Czas gry'],
        'last_played' => ['Last Played', 'Ostatnio uruchomiono'],
        'added' => ['Added', 'Dodano'],
        'released' => ['Release Date', 'Data wydania'],
        'source' => ['Sources', 'Źródła'],
        'game_id' => ['Game Id', 'ID gry'],
        'links' => ['Links', 'Odnośniki'],
        'score' => ['User Score', 'Ocena'],
        'favorite' => ['Favorite', 'Ulubione'],
        'hidden' => ['Hidden', 'Ukryte'],
    ];

    /** The library Playnite names its Steam games' source. */
    private const STEAM = 'steam';

    /** A store page link carries a Steam game's app id. */
    private const STEAM_LINK = '#store\.steampowered\.com/app/(\d+)#';

    /** The prefix of a status's category key; alone, the key of games without one. */
    private const NO_STATUS = 'status:';

    /** The service the list of unplayed games and statuses nobody can read is named after. */
    private const SERVICE = 'Playnite';

    public function code(): string
    {
        return self::CODE;
    }

    public function label(): string
    {
        return __('import.playnite_library_csv');
    }

    public function description(): string
    {
        return __('import.games_in_your_playnite_library_with');
    }

    public function taskLabel(): string
    {
        return __('import.import_from_playnite');
    }

    public function accept(): string
    {
        return '.csv';
    }

    public function notes(): array
    {
        return [
            new SetupNote(__('import.in_playnite_install_library_exporter_advanced'), 'https://github.com/darklinkpower/PlayniteExtensionsCollection'),
            new SetupNote(__('import.playnite_games_are_found_by')),
        ];
    }

    public function analyze(ImportInput $input): ImportManifest
    {
        $csv = $this->csv($input);

        if (! $csv->isValid()) {
            return ImportManifest::invalid(__('import.the_file_is_not_a_playnite'));
        }

        $counts = [];
        $unreadable = 0;

        foreach ($csv->entries() as $row) {
            if ($row instanceof SkipRow) {
                $unreadable++;
            } elseif (! $this->hidden($row)) {
                $status = $this->status($row);
                $counts[$status] = ($counts[$status] ?? 0) + 1;
            }
        }

        $categories = [];

        foreach ($counts as $status => $count) {
            $categories[] = $this->category((string) $status, $count);
        }

        usort($categories, fn (ManifestCategory $a, ManifestCategory $b): int => [! $a->preselected, $a->label] <=> [! $b->preselected, $b->label]);

        return ImportManifest::selectable($categories, unreadable: $unreadable);
    }

    public function rows(ImportInput $input, ImportSelection $selection): Generator
    {
        $list = (new LibraryList(self::SERVICE))->ref();
        $listed = false;

        foreach ($this->csv($input)->entries() as $row) {
            if ($row instanceof SkipRow) {
                yield $row;

                continue;
            }

            $status = $this->status($row);

            if ($this->hidden($row) || ! $selection->has($this->key($status))) {
                continue;
            }

            $outcome = PlayniteOutcome::of($status);

            if ($outcome === PlayniteOutcome::Listed && ! $listed) {
                $listed = true;

                yield new ListRow($list);
            }

            yield $this->game($row, $outcome, $list);
        }
    }

    private function csv(ImportInput $input): CsvStream
    {
        return CsvStream::mapped((string) $input->path, new ColumnMap(self::COLUMNS), ['name']);
    }

    private function category(string $status, int $count): ManifestCategory
    {
        $outcome = PlayniteOutcome::of($status);
        $label = $status === ''
            ? __('import.playnite_no_status')
            : __($outcome->selectionLabel(), ['status' => $status]);
        $category = new ManifestCategory($this->key($status), $label, $count);

        // Unplayed games and statuses nobody can read fill a list, not the
        // library: the user asks for that.
        return $outcome === PlayniteOutcome::Listed ? $category->optIn() : $category;
    }

    /**
     * @param  array<string, string>  $row
     */
    private function game(array $row, PlayniteOutcome $outcome, ImportListRef $list): MediaRow|SkipRow
    {
        $name = trim($row['name']);

        if ($name === '') {
            return new SkipRow('import.skip_playnite_no_name');
        }

        $minutes = Playtime::read(Payload::text($row, 'playtime'));
        $lastPlayed = RegionalDate::day(Payload::text($row, 'last_played'));
        $added = RegionalDate::day(Payload::text($row, 'added'));
        $rating = RatingScale::readPercent(Payload::text($row, 'score'));

        return new MediaRow(
            $this->subject($row, $name),
            plays: $outcome === PlayniteOutcome::Finished
                ? [new ImportPlay($lastPlayed, amount: $minutes, dating: PlayDating::Activity)]
                : [],
            rating: $rating === null ? null : new ImportRating($rating),
            status: match ($outcome) {
                PlayniteOutcome::Playing => new ImportStatus(MediaUserStatus::InProgress, $lastPlayed ?? $added, $minutes),
                PlayniteOutcome::OnHold => new ImportStatus(MediaUserStatus::OnHold, $lastPlayed ?? $added, $minutes),
                default => null,
            },
            drop: $outcome === PlayniteOutcome::Abandoned ? new ImportDrop($lastPlayed, $minutes) : null,
            watchlist: $outcome === PlayniteOutcome::Planned ? new ImportMark($added) : null,
            favorite: Payload::flag($row, 'favorite') ? new ImportMark : null,
            lists: $outcome === PlayniteOutcome::Listed ? [new ImportListEntry($list, $added)] : [],
        );
    }

    /**
     * @param  array<string, string>  $row
     */
    private function subject(array $row, string $name): MediaSubject
    {
        $steam = $this->steamId($row);

        return MediaSubject::external(
            MediaKind::Game,
            $steam === null ? [] : [ExternalIdSource::Steam->value => $steam],
            $name,
            byTitle: new TitleQuery($name, $this->year(Payload::text($row, 'released')), exactTitle: true),
        );
    }

    /**
     * The app id of a game from Steam's library, or of any game linking to
     * its Steam store page.
     *
     * @param  array<string, string>  $row
     */
    private function steamId(array $row): ?string
    {
        $gameId = trim(Payload::text($row, 'game_id'));

        if (mb_strtolower(trim(Payload::text($row, 'source'))) === self::STEAM && ctype_digit($gameId)) {
            return $gameId;
        }

        return preg_match(self::STEAM_LINK, Payload::text($row, 'links'), $match) === 1 ? $match[1] : null;
    }

    /**
     * @param  array<string, string>  $row
     */
    private function status(array $row): string
    {
        return trim(Payload::text($row, 'status'));
    }

    /** A category key: the status as Playnite names it, within what a selection carries. */
    private function key(string $status): string
    {
        return ImportSelection::key(self::NO_STATUS.$status);
    }

    /**
     * @param  array<string, string>  $row
     */
    private function hidden(array $row): bool
    {
        return Payload::flag($row, 'hidden');
    }

    /** The release year, however the region writes the date ("2015", "May 2015", "5/19/2015"). */
    private function year(string $value): ?int
    {
        return preg_match('/\b(\d{4})\b/', $value, $match) === 1 ? (int) $match[1] : null;
    }
}

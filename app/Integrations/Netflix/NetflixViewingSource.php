<?php

namespace App\Integrations\Netflix;

use App\Catalog\MediaKind;
use App\Integrations\Import\Contracts\ExplainsSetup;
use App\Integrations\Import\Contracts\ReadsFile;
use App\Integrations\Import\CsvStream;
use App\Integrations\Import\Data\ImportInput;
use App\Integrations\Import\Data\ImportManifest;
use App\Integrations\Import\Data\ImportPlay;
use App\Integrations\Import\Data\ImportSelection;
use App\Integrations\Import\Data\ManifestCategory;
use App\Integrations\Import\Data\MediaSubject;
use App\Integrations\Import\Data\SetupNote;
use App\Integrations\Import\Data\TitleQuery;
use App\Integrations\Import\Playtime;
use App\Integrations\Import\Rows\MediaRow;
use App\Integrations\Import\Rows\SkipRow;
use App\Integrations\Import\ZipExport;
use App\Library\PlaybackCompletion;
use Carbon\CarbonImmutable;
use Carbon\Exceptions\InvalidFormatException;
use Generator;

/**
 * Netflix's viewing activity from the copy of personal data it sends on
 * request: `ViewingActivity.csv`, alone or in the ZIP it came in. One row
 * per playback of every profile on the account, so the user picks theirs.
 *
 * Trailers, previews and recaps (a supplemental video type) are left out.
 * The playbacks of one title on one day — a film paused and resumed — add
 * up to one playback, which stands as a viewing when it covers the share
 * of the title's runtime every timed playback must ({@see ImportPlay::$playedSeconds}). Netflix names titles in the account's language and
 * without ids or years: a film by its exact name, an episode by its show's
 * exact name, then its title within the season ({@see NetflixTitle}). The
 * weakest source there is, said so under it.
 *
 * The short history the website offers (title and date only) cannot tell
 * a viewing from a trailer, and is refused.
 */
final class NetflixViewingSource implements ExplainsSetup, ReadsFile
{
    public const CODE = 'netflix';

    /** Where the file sits in the ZIP of personal data. */
    private const ENTRY = 'ViewingActivity.csv';

    private const COLUMNS = ['Profile Name', 'Start Time', 'Duration', 'Title', 'Supplemental Video Type'];

    /** The history from the account page: a title and a day, nothing else. */
    private const SHORT_HISTORY = ['Title', 'Date'];

    /** A category key: the profile, within what a selection carries. */
    private const PROFILE = 'profile:';

    public function code(): string
    {
        return self::CODE;
    }

    public function label(): string
    {
        return __('import.netflix_viewing_activity');
    }

    public function description(): string
    {
        return __('import.films_and_episodes_one_profile_watched');
    }

    public function taskLabel(): string
    {
        return __('import.import_from_netflix');
    }

    public function accept(): string
    {
        return '.csv,.zip';
    }

    public function notes(): array
    {
        return [
            new SetupNote(__('import.on_netflix_request_a_copy_of'), 'https://www.netflix.com/account/getmyinfo'),
            new SetupNote(__('import.netflix_is_experimental_titles_are', ['percent' => PlaybackCompletion::threshold()])),
        ];
    }

    public function analyze(ImportInput $input): ImportManifest
    {
        $csv = $this->csv($input);

        if ($csv === null) {
            return ImportManifest::invalid(__(new CsvStream((string) $input->path, self::SHORT_HISTORY)->isValid()
                ? 'import.the_netflix_viewing_history_from_the'
                : 'import.the_file_is_not_netflix_viewing'));
        }

        $counts = [];
        $unreadable = 0;

        foreach ($this->viewings($csv) as $viewing) {
            if ($viewing instanceof SkipRow) {
                $unreadable++;
            } else {
                $counts[$viewing['profile']] = ($counts[$viewing['profile']] ?? 0) + 1;
            }
        }

        $categories = [];

        foreach ($counts as $profile => $count) {
            $categories[] = new ManifestCategory($this->key((string) $profile), (string) $profile, $count);
        }

        return ImportManifest::pick($categories, $unreadable);
    }

    public function rows(ImportInput $input, ImportSelection $selection): Generator
    {
        $csv = $this->csv($input);

        foreach ($csv === null ? [] : $this->viewings($csv) as $viewing) {
            if ($viewing instanceof SkipRow) {
                yield $viewing;
            } elseif ($selection->has($this->key($viewing['profile']))) {
                yield new MediaRow($this->subject($viewing['title']), plays: [new ImportPlay($viewing['at'], playedSeconds: $viewing['seconds'])]);
            }
        }
    }

    /** The file itself, or the one in the ZIP; null when neither is the viewing activity. */
    private function csv(ImportInput $input): ?CsvStream
    {
        $path = (string) $input->path;
        $zip = new ZipExport($path);
        $entry = null;

        foreach ($zip->names() as $name) {
            if (basename($name) === self::ENTRY) {
                $entry = $name;
            }
        }

        $csv = $entry === null ? new CsvStream($path, self::COLUMNS) : $zip->csv($entry, self::COLUMNS);

        return $csv->isValid() ? $csv : null;
    }

    /**
     * The playbacks of every profile, those of one title on one day (in the
     * instance's timezone) added up into one, dated by the first. Netflix
     * lists the newest first, so the file is read through before any is
     * given back.
     *
     * @return Generator<int, array{profile: string, title: string, at: CarbonImmutable, seconds: int}|SkipRow>
     */
    private function viewings(CsvStream $csv): Generator
    {
        $timezone = config()->string('app.timezone');
        $viewings = [];

        foreach ($csv->entries() as $row) {
            if ($row instanceof SkipRow) {
                yield $row;

                continue;
            }

            if (trim($row['Supplemental Video Type']) !== '') {
                continue;
            }

            $at = $this->startedAt($row['Start Time']);
            $title = trim($row['Title']);

            if ($at === null || $title === '') {
                yield new SkipRow('import.skip_netflix_unreadable', ['label' => $title === '' ? trim($row['Start Time']) : $title]);

                continue;
            }

            $profile = trim($row['Profile Name']);
            $key = implode("\0", [$profile, $title, $at->setTimezone($timezone)->toDateString()]);
            $seconds = (Playtime::seconds($row['Duration']) ?? 0) + ($viewings[$key]['seconds'] ?? 0);
            $first = isset($viewings[$key]) && $viewings[$key]['at']->lessThan($at) ? $viewings[$key]['at'] : $at;

            $viewings[$key] = ['profile' => $profile, 'title' => $title, 'at' => $first, 'seconds' => $seconds];
        }

        yield from array_values($viewings);
    }

    private function subject(string $value): MediaSubject
    {
        $title = NetflixTitle::read($value);

        if (! $title->isEpisode()) {
            return MediaSubject::external(MediaKind::Movie, [], $value, byTitle: new TitleQuery($title->title, exactTitle: true));
        }

        return MediaSubject::external(
            MediaKind::Episode,
            [],
            $value,
            season: $title->season,
            episode: $title->episode,
            byTitle: new TitleQuery($title->title, exactTitle: true),
            episodeTitle: $title->episodeTitle,
        );
    }

    /** Netflix writes when a playback began in UTC: "2024-03-09 21:15:02". */
    private function startedAt(string $value): ?CarbonImmutable
    {
        try {
            return CarbonImmutable::createFromFormat('Y-m-d H:i:s', trim($value), 'UTC');
        } catch (InvalidFormatException) {
            return null;
        }
    }

    private function key(string $profile): string
    {
        return ImportSelection::key(self::PROFILE.$profile);
    }
}

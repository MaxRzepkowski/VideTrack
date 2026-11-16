<?php

namespace App\Integrations\Jellyfin;

use App\Catalog\MediaKind;
use App\Integrations\EpisodeLabel;
use App\Integrations\Import\Contracts\ExplainsSetup;
use App\Integrations\Import\Contracts\ReadsFile;
use App\Integrations\Import\Data\ImportInput;
use App\Integrations\Import\Data\ImportManifest;
use App\Integrations\Import\Data\ImportPlay;
use App\Integrations\Import\Data\ImportSelection;
use App\Integrations\Import\Data\ManifestCategory;
use App\Integrations\Import\Data\MediaSubject;
use App\Integrations\Import\Data\SetupNote;
use App\Integrations\Import\Data\TitleQuery;
use App\Integrations\Import\Rows\MediaRow;
use App\Integrations\Import\Rows\SkipRow;
use App\Library\PlaybackCompletion;
use App\Library\ScrobbleDedup;
use App\Support\Payload;
use Generator;

/**
 * A backup of Jellyfin's Playback Reporting plugin (its settings → Save
 * Backup Data, written to the folder set there): every playback the server
 * logged, for every user, from before a webhook was there to hear it.
 *
 * The plugin writes its table as it stands: one playback per line, nine
 * tab-separated fields, no header and no quoting — date (the server's
 * local time without an offset, read in this instance's timezone, which
 * every play is stamped in), user id, item id, item type, item name, method, client,
 * device, seconds played. The ids are the server's own, so a film is
 * found by its exact name and an episode by its show's
 * ("Show - s01e02 - Title"). The log holds no verdict on a playback, only
 * its length: a play stands where it covers the title's runtime.
 *
 * The household shares one log, so its users are the categories and none
 * is ticked until the user picks theirs. Music and other items are not
 * tracked here and pass unremarked.
 */
final class PlaybackReportingSource implements ExplainsSetup, ReadsFile
{
    public const CODE = 'jellyfin-playback-reporting';

    private const FIELDS = 9;

    private const MOVIE = 'Movie';

    private const EPISODE = 'Episode';

    /** "Show - s01e02 - Title"; the plugin writes 00 for a number it lacks. */
    private const EPISODE_NAME = '/^(?<show>.+?) - s(?<season>\d+)e(?<episode>\d+) - /';

    private const USER_ID = '/^[0-9a-f]{32}$/i';

    public function code(): string
    {
        return self::CODE;
    }

    public function label(): string
    {
        return __('import.jellyfin_playback_reporting_backup_tsv');
    }

    public function description(): string
    {
        return __('import.films_and_episodes_your_jellyfin_server');
    }

    public function taskLabel(): string
    {
        return __('import.import_from_jellyfin_playback_reporting');
    }

    public function accept(): string
    {
        return '.tsv';
    }

    public function notes(): array
    {
        return [
            new SetupNote(__('import.in_jellyfin_open_playback_reporting_settings')),
            new SetupNote(__('import.a_viewing_the_webhook_already_logged', ['hours' => ScrobbleDedup::windowHours()])),
            new SetupNote(__('import.titles_are_found_by_their_exact', ['threshold' => PlaybackCompletion::threshold()])),
        ];
    }

    public function analyze(ImportInput $input): ImportManifest
    {
        $counts = [];

        foreach ($this->playbacks($input) as $playback) {
            $counts[$playback['user']] = ($counts[$playback['user']] ?? 0) + 1;
        }

        if ($counts === []) {
            return ImportManifest::invalid(__('import.the_file_is_not_a_playback'));
        }

        $categories = [];

        foreach ($counts as $user => $count) {
            $categories[] = new ManifestCategory((string) $user, (string) $user, $count);
        }

        return ImportManifest::pick($categories);
    }

    public function rows(ImportInput $input, ImportSelection $selection): Generator
    {
        foreach ($this->playbacks($input) as $playback) {
            if ($selection->has($playback['user'])) {
                yield $this->row($playback);
            }
        }
    }

    /**
     * @param  array{user: string, type: string, name: string, at: string, seconds: int}  $playback
     */
    private function row(array $playback): MediaRow|SkipRow
    {
        $at = Payload::instant($playback['at']);
        $play = new ImportPlay($at, scrobbled: true, playedSeconds: $playback['seconds']);
        $name = trim($playback['name']);

        if ($playback['type'] === self::MOVIE) {
            return $name === ''
                ? new SkipRow('import.skip_playback_reporting_no_name')
                : new MediaRow(MediaSubject::external(MediaKind::Movie, [], $name, byTitle: new TitleQuery($name, exactTitle: true)), plays: [$play]);
        }

        if (preg_match(self::EPISODE_NAME, $name, $parts) !== 1 || (int) $parts['episode'] === 0) {
            return new SkipRow('import.skip_playback_reporting_episode_name', ['label' => $name]);
        }

        $show = trim($parts['show']);

        return new MediaRow(
            MediaSubject::external(
                MediaKind::Episode,
                [],
                EpisodeLabel::of($show, (int) $parts['season'], (int) $parts['episode']),
                season: (int) $parts['season'],
                episode: (int) $parts['episode'],
                byTitle: new TitleQuery($show, exactTitle: true),
            ),
            plays: [$play],
        );
    }

    /**
     * The film and episode playbacks of the log, one line at a time; a line
     * of another width, without a user or without a length in seconds, is
     * not one of the plugin's.
     *
     * @return Generator<int, array{user: string, type: string, name: string, at: string, seconds: int}>
     */
    private function playbacks(ImportInput $input): Generator
    {
        $path = (string) $input->path;
        $stream = is_file($path) ? fopen($path, 'r') : false;

        if ($stream === false) {
            return;
        }

        try {
            while (($line = fgets($stream)) !== false) {
                $fields = explode("\t", rtrim($line, "\r\n"));

                if (count($fields) !== self::FIELDS || preg_match(self::USER_ID, $fields[1]) !== 1 || ! ctype_digit(trim($fields[8]))) {
                    continue;
                }

                if (in_array($fields[3], [self::MOVIE, self::EPISODE], true)) {
                    yield ['user' => mb_strtolower($fields[1]), 'type' => $fields[3], 'name' => $fields[4], 'at' => $fields[0], 'seconds' => (int) trim($fields[8])];
                }
            }
        } finally {
            fclose($stream);
        }
    }
}

<?php

namespace App\Integrations\AnimePlanet;

use App\Catalog\MediaKind;
use App\Enums\MediaUserStatus;
use App\Integrations\Import\Contracts\ExplainsSetup;
use App\Integrations\Import\Contracts\ReadsFile;
use App\Integrations\Import\Data\ImportDrop;
use App\Integrations\Import\Data\ImportInput;
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
use App\Integrations\Import\Rows\MediaRow;
use App\Integrations\Import\Rows\SkipRow;
use App\Library\RatingScale;
use App\Support\Payload;
use Generator;

/**
 * An Anime-Planet manga list as its export page saves it: JSON with the
 * user and one entry per title — `name`, `status`, `started` and
 * `completed` ("2020-01-01 00:00:00" or null), `rating` in half stars,
 * `ch` and `vol` read so far.
 *
 * Only manga: Anime-Planet names titles without ids, and an anime placed by
 * its name lands on the wrong season of a show, or on the wrong show. An
 * anime list is told apart by its episodes and refused. Manga is found by
 * its exact title, Anime-Planet's English one among the names the manga
 * provider knows.
 */
final class AnimePlanetJsonSource implements ExplainsSetup, ReadsFile
{
    public const CODE = 'anime-planet';

    /** The statuses of an anime list, which says so by them alone. */
    private const ANIME_STATUSES = ['watched', 'watching', 'want to watch', "won't watch"];

    public function code(): string
    {
        return self::CODE;
    }

    public function label(): string
    {
        return __('import.anime_planet_manga_list_json');
    }

    public function description(): string
    {
        return __('import.manga_you_read_are_reading_stalled');
    }

    public function taskLabel(): string
    {
        return __('import.import_from_anime_planet');
    }

    public function accept(): string
    {
        return '.json';
    }

    public function notes(): array
    {
        return [
            new SetupNote(__('import.on_anime_planet_export_your_manga'), 'https://www.anime-planet.com/users/export_list.php'),
            new SetupNote(__('import.anime_planet_manga_is_found_by')),
        ];
    }

    public function analyze(ImportInput $input): ImportManifest
    {
        $entries = $this->entries($input);

        if ($entries === null) {
            return ImportManifest::invalid(__('import.the_file_is_not_an_anime_planet'));
        }

        if ($this->isAnime($entries)) {
            return ImportManifest::invalid(__('import.anime_planet_anime_lists_are_not'));
        }

        $counts = array_fill_keys(array_column(AnimePlanetStatus::cases(), 'value'), 0);

        foreach ($entries as $entry) {
            $status = $this->status($entry);

            if ($status !== null) {
                $counts[$status->value]++;
            }
        }

        return ImportManifest::selectable(array_map(
            fn (AnimePlanetStatus $status): ManifestCategory => new ManifestCategory($status->value, __($status->importLabel()), $counts[$status->value]),
            AnimePlanetStatus::cases(),
        ));
    }

    public function rows(ImportInput $input, ImportSelection $selection): Generator
    {
        foreach ($this->entries($input) ?? [] as $entry) {
            $status = $this->status($entry);

            if ($status === null || ! $selection->has($status->value)) {
                continue;
            }

            $name = Payload::text($entry, 'name');

            yield $name === '' ? new SkipRow('import.skip_anime_planet_no_name') : $this->manga($entry, $name, $status);
        }
    }

    /**
     * @param  array<array-key, mixed>  $entry
     */
    private function manga(array $entry, string $name, AnimePlanetStatus $status): MediaRow
    {
        $chapters = Payload::positiveInt($entry, 'ch');
        $started = Payload::calendarDay($entry['started'] ?? null);
        $completed = Payload::calendarDay($entry['completed'] ?? null);
        $rating = Payload::float($entry, 'rating');
        $stars = $rating === null ? null : RatingScale::readStars((string) $rating);

        return new MediaRow(
            MediaSubject::external(MediaKind::Manga, [], $name, byTitle: new TitleQuery($name, exactTitle: true)),
            plays: $status === AnimePlanetStatus::Read ? [new ImportPlay($completed, amount: $chapters)] : [],
            rating: $stars === null ? null : new ImportRating($stars, $completed),
            status: match ($status) {
                AnimePlanetStatus::Reading => new ImportStatus(MediaUserStatus::InProgress, $started, $chapters),
                AnimePlanetStatus::Stalled => new ImportStatus(MediaUserStatus::OnHold, $started, $chapters),
                default => null,
            },
            drop: $status === AnimePlanetStatus::Dropped ? new ImportDrop($completed ?? $started, $chapters) : null,
            watchlist: $status === AnimePlanetStatus::WantToRead ? new ImportMark : null,
        );
    }

    /**
     * The export's entries; null when the file is no such export.
     *
     * @return list<array<array-key, mixed>>|null
     */
    private function entries(ImportInput $input): ?array
    {
        $path = (string) $input->path;
        $export = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;

        return is_array($export) && is_array($export['entries'] ?? null) ? Payload::list($export['entries']) : null;
    }

    /**
     * @param  list<array<array-key, mixed>>  $entries
     */
    private function isAnime(array $entries): bool
    {
        foreach ($entries as $entry) {
            if (array_key_exists('eps', $entry) || in_array(mb_strtolower(Payload::text($entry, 'status')), self::ANIME_STATUSES, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<array-key, mixed>  $entry
     */
    private function status(array $entry): ?AnimePlanetStatus
    {
        return AnimePlanetStatus::tryFrom(mb_strtolower(Payload::text($entry, 'status')));
    }
}

<?php

namespace App\Integrations\WeTrakr;

use App\Catalog\ExternalIdSource;
use App\Catalog\MediaKind;
use App\Integrations\EpisodeLabel;
use App\Integrations\Import\Contracts\ExplainsSetup;
use App\Integrations\Import\Contracts\ReadsFile;
use App\Integrations\Import\Data\ImportDrop;
use App\Integrations\Import\Data\ImportInput;
use App\Integrations\Import\Data\ImportListEntry;
use App\Integrations\Import\Data\ImportListRef;
use App\Integrations\Import\Data\ImportManifest;
use App\Integrations\Import\Data\ImportMark;
use App\Integrations\Import\Data\ImportPlay;
use App\Integrations\Import\Data\ImportRating;
use App\Integrations\Import\Data\ImportSelection;
use App\Integrations\Import\Data\ManifestCategory;
use App\Integrations\Import\Data\MediaSubject;
use App\Integrations\Import\Data\SetupNote;
use App\Integrations\Import\Data\TitleQuery;
use App\Integrations\Import\ImportCategory;
use App\Integrations\Import\OwnLists;
use App\Integrations\Import\Rows\ListRow;
use App\Integrations\Import\Rows\MediaRow;
use App\Integrations\Import\Rows\SkipRow;
use App\Library\RatingScale;
use App\Support\Payload;
use Closure;
use Generator;

/**
 * A WeTrakr export ({@see WeTrakrArchive}).
 *
 * The tracklog holds the history (movies and episodes watched), the
 * watchlist and the dropped titles; ratings, lists and favorites have files
 * of their own. Movies and shows are placed by their TMDB ids. An episode is
 * placed below its show when the export names the show's id, else by its
 * own IMDb id, else by its show's name and its numbers.
 *
 * A viewing WeTrakr kept no date for (one it brought over from elsewhere)
 * still counts, on the title's release date: the export has nothing truer.
 * A season's rating and a season on a list are left out: the export does
 * not say which season they are.
 */
final class WeTrakrZipSource implements ExplainsSetup, ReadsFile
{
    public const CODE = 'wetrakr';

    /** The dropped titles of the tracklog, a category of their own. */
    private const DROPPED = 'dropped';

    public function code(): string
    {
        return self::CODE;
    }

    public function label(): string
    {
        return __('import.wetrakr_export_zip');
    }

    public function description(): string
    {
        return __('import.movies_and_episodes_you_watched_on_wetrakr');
    }

    public function taskLabel(): string
    {
        return __('import.import_from_wetrakr');
    }

    public function accept(): string
    {
        return '.zip';
    }

    public function notes(): array
    {
        return [new SetupNote(__('import.on_wetrakr_export_your_data'))];
    }

    public function analyze(ImportInput $input): ImportManifest
    {
        $archive = $this->archive($input);

        if (! $archive->isValid()) {
            return ImportManifest::invalid(__('import.the_file_is_not_a_wetrakr'));
        }

        $lists = new OwnLists;

        foreach ($archive->rows(WeTrakrSection::Lists) as $row) {
            $lists->count(Payload::text($row, 'list_name'));
        }

        return ImportManifest::selectable([
            ImportCategory::History->counted(iterator_count($this->tracked($archive, WeTrakrStatus::Watched, [MediaKind::Movie, MediaKind::Episode]))),
            ImportCategory::Ratings->counted(iterator_count($archive->rows(WeTrakrSection::Ratings))),
            ImportCategory::Watchlist->counted(iterator_count($this->tracked($archive, WeTrakrStatus::PlanToWatch, [MediaKind::Movie, MediaKind::Show]))),
            new ManifestCategory(self::DROPPED, __('common.dropped'), iterator_count($this->tracked($archive, WeTrakrStatus::Discarded, [MediaKind::Movie, MediaKind::Show]))),
            ImportCategory::Favorites->counted(iterator_count($archive->rows(WeTrakrSection::Favorites))),
        ], $lists->offered(), iterator_count($archive->malformed()));
    }

    public function rows(ImportInput $input, ImportSelection $selection): Generator
    {
        $archive = $this->archive($input);

        yield from $archive->malformed();

        if ($selection->has(ImportCategory::History->value)) {
            foreach ($this->tracked($archive, WeTrakrStatus::Watched, [MediaKind::Movie, MediaKind::Episode]) as $row) {
                yield $this->media($archive, $row, fn (MediaSubject $subject): MediaRow => new MediaRow(
                    $subject,
                    plays: [new ImportPlay(WeTrakrArchive::date($row['tracked_at']))],
                ));
            }
        }

        if ($selection->has(ImportCategory::Ratings->value)) {
            yield from $this->ratings($archive);
        }

        if ($selection->has(ImportCategory::Watchlist->value)) {
            foreach ($this->tracked($archive, WeTrakrStatus::PlanToWatch, [MediaKind::Movie, MediaKind::Show]) as $row) {
                yield $this->media($archive, $row, fn (MediaSubject $subject): MediaRow => new MediaRow(
                    $subject,
                    watchlist: new ImportMark(WeTrakrArchive::date($row['tracked_at'])),
                ));
            }
        }

        if ($selection->has(self::DROPPED)) {
            foreach ($this->tracked($archive, WeTrakrStatus::Discarded, [MediaKind::Movie, MediaKind::Show]) as $row) {
                yield $this->media($archive, $row, fn (MediaSubject $subject): MediaRow => new MediaRow(
                    $subject,
                    drop: new ImportDrop(WeTrakrArchive::date($row['tracked_at'])),
                ));
            }
        }

        if ($selection->has(ImportCategory::Favorites->value)) {
            foreach ($archive->rows(WeTrakrSection::Favorites) as $row) {
                yield $this->media($archive, $row, fn (MediaSubject $subject): MediaRow => new MediaRow(
                    $subject,
                    favorite: new ImportMark(WeTrakrArchive::date($row['created_at'])),
                ));
            }
        }

        foreach ($selection->listIds as $key) {
            yield from $this->list($archive, $key);
        }
    }

    private function archive(ImportInput $input): WeTrakrArchive
    {
        return new WeTrakrArchive((string) $input->path);
    }

    /**
     * The tracklog's rows of one status about these kinds of title.
     *
     * @param  list<MediaKind>  $kinds
     * @return Generator<int, array<string, string>>
     */
    private function tracked(WeTrakrArchive $archive, WeTrakrStatus $status, array $kinds): Generator
    {
        foreach ($archive->rows(WeTrakrSection::Tracklog) as $row) {
            if (Payload::enum($row, 'status', WeTrakrStatus::class) === $status && in_array(WeTrakrArchive::kind($row), $kinds, true)) {
                yield $row;
            }
        }
    }

    /**
     * WeTrakr rates on the ten-point scale.
     *
     * @return Generator<int, MediaRow|SkipRow>
     */
    private function ratings(WeTrakrArchive $archive): Generator
    {
        foreach ($archive->rows(WeTrakrSection::Ratings) as $row) {
            // A fraction rounds to the scale; a whole number off it is the run's to report.
            $rating = RatingScale::readPoints(Payload::text($row, 'rating')) ?? Payload::int($row, 'rating');

            if ($rating === null) {
                yield new SkipRow('import.skip_wetrakr_no_rating', ['label' => $this->rowLabel($row)]);

                continue;
            }

            yield $this->media($archive, $row, fn (MediaSubject $subject): MediaRow => new MediaRow(
                $subject,
                rating: new ImportRating($rating, WeTrakrArchive::date($row['rated_at'])),
            ));
        }
    }

    /**
     * One list, by the key the selection carries back, in the order WeTrakr
     * ranks it. Rows of one name are one list.
     *
     * @return Generator<int, ListRow|MediaRow|SkipRow>
     */
    private function list(WeTrakrArchive $archive, string $key): Generator
    {
        $items = array_values(array_filter(
            iterator_to_array($archive->rows(WeTrakrSection::Lists), false),
            fn (array $row): bool => Payload::text($row, 'list_name') !== '' && OwnLists::key(Payload::text($row, 'list_name')) === $key,
        ));

        if ($items === []) {
            return;
        }

        $list = new ImportListRef(Payload::text($items[0], 'list_name'), description: Payload::string($items[0], 'list_description'));
        usort($items, fn (array $a, array $b): int => (Payload::int($a, 'rank') ?? 0) <=> (Payload::int($b, 'rank') ?? 0));

        yield new ListRow($list);

        foreach ($items as $row) {
            $added = WeTrakrArchive::date(Payload::text($row, 'created_at'));

            yield $this->media($archive, $row, fn (MediaSubject $subject): MediaRow => new MediaRow(
                $subject,
                lists: [new ImportListEntry($list, $added)],
            ));
        }
    }

    /**
     * A row placed and handed to $build; a skip when nothing in it says
     * which title it is.
     *
     * @param  array<string, string>  $row
     * @param  Closure(MediaSubject): MediaRow  $build
     */
    private function media(WeTrakrArchive $archive, array $row, Closure $build): MediaRow|SkipRow
    {
        if (WeTrakrArchive::kind($row) === MediaKind::Season) {
            return new SkipRow('import.skip_wetrakr_season', ['label' => $this->rowLabel($row)]);
        }

        $subject = $this->subject($archive, $row);

        return $subject === null ? new SkipRow('import.skip_wetrakr_unknown_place', ['label' => $this->rowLabel($row)]) : $build($subject);
    }

    /**
     * @param  array<string, string>  $row
     */
    private function subject(WeTrakrArchive $archive, array $row): ?MediaSubject
    {
        $kind = WeTrakrArchive::kind($row);
        $tmdb = Payload::id($row['tmdb_id']);

        if (($kind === MediaKind::Movie || $kind === MediaKind::Show) && $tmdb !== null) {
            return MediaSubject::direct($kind->value, 'tmdb', $tmdb, $this->rowLabel($row));
        }

        if ($kind !== MediaKind::Episode) {
            return null;
        }

        // A list names an episode by its id alone; another row numbers it.
        $place = WeTrakrArchive::place($row) ?? ($tmdb === null ? null : $archive->episode($tmdb));
        $imdb = Payload::text($row, 'imdb_id');

        if ($place === null) {
            return $imdb === '' ? null : MediaSubject::external(MediaKind::Episode, [ExternalIdSource::Imdb->value => $imdb], $this->rowLabel($row));
        }

        [$show, $season, $number] = $place;
        $label = EpisodeLabel::of($show, $season, $number);
        $showId = $archive->showId($show);

        return $showId !== null
            ? MediaSubject::below(MediaKind::Show->value, 'tmdb', $showId, [$season, $number], $label)
            : MediaSubject::external(
                MediaKind::Episode,
                $imdb === '' ? [] : [ExternalIdSource::Imdb->value => $imdb],
                $label,
                season: $season,
                episode: $number,
                byTitle: new TitleQuery($show),
            );
    }

    /**
     * How the report names a row: an episode by its show and place, else its title.
     *
     * @param  array<string, string>  $row
     */
    private function rowLabel(array $row): string
    {
        $place = WeTrakrArchive::place($row);
        $title = Payload::text($row, 'title');

        return match (true) {
            $place !== null => EpisodeLabel::of(...$place),
            $title !== '' => $title,
            default => __('import.untitled_entry'),
        };
    }
}

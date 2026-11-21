<?php

namespace App\Integrations\Imdb;

use App\Catalog\ExternalIdSource;
use App\Catalog\ImdbId;
use App\Catalog\MediaKind;
use App\Integrations\Import\Contracts\ExplainsSetup;
use App\Integrations\Import\Contracts\ReadsFile;
use App\Integrations\Import\CsvStream;
use App\Integrations\Import\Data\ImportInput;
use App\Integrations\Import\Data\ImportManifest;
use App\Integrations\Import\Data\ImportMark;
use App\Integrations\Import\Data\ImportRating;
use App\Integrations\Import\Data\ImportSelection;
use App\Integrations\Import\Data\MediaSubject;
use App\Integrations\Import\Data\SetupNote;
use App\Integrations\Import\Data\TitleQuery;
use App\Integrations\Import\ImportCategory;
use App\Integrations\Import\Rows\MediaRow;
use App\Integrations\Import\Rows\SkipRow;
use App\Integrations\TitleLabel;
use App\Library\RatingScale;
use App\Support\Payload;
use Generator;

/**
 * An IMDb list export (CSV): your ratings, your watchlist, or any list. All of
 * them share one header — `Const, Created, …, Title Type, …, Your Rating,
 * Date Rated` — so the file cannot say which it is. The rows can: a row with
 * a rating is a rating, a row without one is a title you want to see. The
 * user ticks either or both.
 *
 * Titles are placed through their tt id at TMDB (`/find`), episodes included;
 * films and series without a match fall back to title + year.
 */
final class ImdbCsvSource implements ExplainsSetup, ReadsFile
{
    public const CODE = 'imdb';

    /** IMDb "Title Type" (lowercased) → what we track it as. */
    private const TYPES = [
        'movie' => MediaKind::Movie,
        'short' => MediaKind::Movie,
        'tv movie' => MediaKind::Movie,
        'tv special' => MediaKind::Movie,
        'video' => MediaKind::Movie,
        'tv series' => MediaKind::Show,
        'tv mini series' => MediaKind::Show,
        'tv episode' => MediaKind::Episode,
    ];

    public function code(): string
    {
        return self::CODE;
    }

    public function label(): string
    {
        return __('import.imdb_export_csv');
    }

    public function description(): string
    {
        return __('import.your_ratings_or_your_watchlist_one');
    }

    public function taskLabel(): string
    {
        return __('import.import_from_imdb');
    }

    public function accept(): string
    {
        return '.csv';
    }

    public function notes(): array
    {
        return [new SetupNote(__('import.on_imdb_open_your_ratings_or'))];
    }

    public function analyze(ImportInput $input): ImportManifest
    {
        $csv = $this->csv($input);

        if (! $csv->isValid()) {
            return ImportManifest::invalid(__('import.the_file_is_not_an_imdb'));
        }

        $rated = 0;
        $unrated = 0;
        $unreadable = 0;

        foreach ($csv->entries() as $row) {
            match (true) {
                $row instanceof SkipRow => $unreadable++,
                $this->rating($row) === null => $unrated++,
                default => $rated++,
            };
        }

        return ImportManifest::selectable([
            ImportCategory::Ratings->counted($rated),
            ImportCategory::Watchlist->counted($unrated),
        ], unreadable: $unreadable);
    }

    public function rows(ImportInput $input, ImportSelection $selection): Generator
    {
        foreach ($this->csv($input)->entries() as $row) {
            if ($row instanceof SkipRow) {
                yield $row;

                continue;
            }

            $rating = $this->rating($row);

            if (! $selection->has(($rating === null ? ImportCategory::Watchlist : ImportCategory::Ratings)->value)) {
                continue;
            }

            yield $this->row($row, $rating);
        }
    }

    private function csv(ImportInput $input): CsvStream
    {
        return new CsvStream((string) $input->path, ['const', 'title type'], lowercase: true);
    }

    /**
     * @param  array<string, string>  $row
     */
    private function row(array $row, ?int $rating): MediaRow|SkipRow
    {
        $title = trim($row['title'] ?? '');
        $year = $this->year($row);
        $label = $title === '' ? trim($row['const']) : TitleLabel::of($title, $year);

        // Older exports drop the tt prefix; TMDB's find needs it.
        $id = ImdbId::read($row['const']);

        if ($id === null) {
            return new SkipRow('import.skip_imdb_no_id', ['label' => $label]);
        }

        $titleType = trim($row['title type']);
        $kind = self::TYPES[mb_strtolower($titleType)] ?? null;

        if ($kind === null) {
            return new SkipRow('import.skip_imdb_title_type', ['label' => $label, 'type' => $titleType]);
        }

        if ($rating === null && $kind === MediaKind::Episode) {
            return new SkipRow('import.skip_imdb_episode_on_watchlist', ['label' => $label]);
        }

        // An episode's title reads "Show: Episode" and names no episode we
        // could search for; only its id can place it.
        $subject = MediaSubject::external(
            $kind,
            [ExternalIdSource::Imdb->value => $id],
            $label,
            byTitle: $kind === MediaKind::Episode || $title === '' ? null : new TitleQuery($title, $year),
        );

        return $rating === null
            ? new MediaRow($subject, watchlist: new ImportMark(Payload::instant($row['created'] ?? null)))
            : new MediaRow($subject, rating: new ImportRating($rating, Payload::instant($row['date rated'] ?? null)));
    }

    /**
     * "Your Rating" on IMDb's own 1–10 scale; null when the row carries none.
     *
     * @param  array<string, string>  $row
     */
    private function rating(array $row): ?int
    {
        return RatingScale::readPoints(Payload::text($row, 'your rating'));
    }

    /**
     * @param  array<string, string>  $row
     */
    private function year(array $row): ?int
    {
        $year = trim($row['year'] ?? '');

        return ctype_digit($year) ? (int) $year : null;
    }
}

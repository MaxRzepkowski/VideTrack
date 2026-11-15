<?php

namespace App\Integrations\Letterboxd;

use App\Integrations\Export\CsvExport;
use App\Integrations\Export\ExportShelf;
use App\Integrations\Export\TitleRecord;
use App\Integrations\Export\TitleRecords;
use App\Models\User;

/**
 * Letterboxd's own import CSV. Letterboxd is film-only, so shows stay out
 * rather than being matched against whatever film shares their title;
 * `Rating10` carries the 1–10 scale unchanged, so neither side rescales, and
 * `tmdbID` is the id Letterboxd matches films by.
 */
final class LetterboxdExport extends CsvExport
{
    public const CODE = 'letterboxd';

    public function __construct(private readonly TitleRecords $records) {}

    public function code(): string
    {
        return self::CODE;
    }

    public function label(): string
    {
        // A product name, never translated.
        return 'Letterboxd';
    }

    public function shelf(): ExportShelf
    {
        return ExportShelf::Service;
    }

    protected function header(): array
    {
        return ['Title', 'Year', 'Rating10', 'WatchedDate', 'tmdbID'];
    }

    protected function rows(User $user): iterable
    {
        foreach ($this->records->of($user, ['movie']) as $record) {
            /** @var TitleRecord $record */
            yield [$record->title, $record->year, $record->rating, $record->watchedOn, $record->provider === 'tmdb' ? $record->ref : null];
        }
    }
}

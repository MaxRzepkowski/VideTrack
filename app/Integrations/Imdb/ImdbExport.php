<?php

namespace App\Integrations\Imdb;

use App\Integrations\Export\CsvExport;
use App\Integrations\Export\ExportShelf;
use App\Integrations\Export\TitleRecord;
use App\Integrations\Export\TitleRecords;
use App\Models\User;

/**
 * IMDb's ratings CSV, the same shape its own export has. IMDb keys rows by
 * `Const`, its title id, and covers shows too; a row without that id still
 * matches on title and year, just less reliably.
 */
final class ImdbExport extends CsvExport
{
    public const CODE = 'imdb';

    public function __construct(private readonly TitleRecords $records) {}

    public function code(): string
    {
        return self::CODE;
    }

    public function label(): string
    {
        // A product name, never translated.
        return 'IMDb';
    }

    public function shelf(): ExportShelf
    {
        return ExportShelf::Service;
    }

    protected function header(): array
    {
        return ['Const', 'Your Rating', 'Date Rated', 'Title', 'Title Type', 'Year'];
    }

    protected function rows(User $user): iterable
    {
        foreach ($this->records->of($user, ['movie', 'show']) as $record) {
            /** @var TitleRecord $record */
            yield [
                $record->imdbId,
                $record->rating,
                $record->ratedOn,
                $record->title,
                $record->type === 'movie' ? 'Movie' : 'TV Series',
                $record->year,
            ];
        }
    }
}

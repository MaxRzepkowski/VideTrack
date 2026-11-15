<?php

namespace App\Integrations\VideTrack\Export;

use App\Integrations\Export\CsvExport;
use App\Integrations\Export\ExportShelf;
use App\Integrations\VideTrack\Backup\BackupEntries;
use App\Models\User;

/** Every rating as a flat sheet. */
final class RatingsSheet extends CsvExport
{
    public const CODE = 'ratings';

    public function __construct(private readonly BackupEntries $entries) {}

    public function code(): string
    {
        return self::CODE;
    }

    public function label(): string
    {
        return __('common.ratings');
    }

    public function shelf(): ExportShelf
    {
        return ExportShelf::Spreadsheet;
    }

    protected function header(): array
    {
        return ['type', 'provider', 'ref', 'title', 'rating'];
    }

    protected function rows(User $user): iterable
    {
        foreach ($this->entries->ratings($user) as $rating) {
            yield [...array_values($rating->position->toArray()), $rating->rating];
        }
    }
}

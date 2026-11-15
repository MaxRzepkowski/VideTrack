<?php

namespace App\Integrations\VideTrack\Export;

use App\Integrations\Export\CsvExport;
use App\Integrations\Export\ExportShelf;
use App\Integrations\VideTrack\Backup\BackupEntries;
use App\Models\User;

/** Every play, in the order it happened, as a flat sheet. */
final class HistorySheet extends CsvExport
{
    public const CODE = 'history';

    public function __construct(private readonly BackupEntries $entries) {}

    public function code(): string
    {
        return self::CODE;
    }

    public function label(): string
    {
        return __('common.history');
    }

    public function shelf(): ExportShelf
    {
        return ExportShelf::Spreadsheet;
    }

    protected function header(): array
    {
        return ['type', 'provider', 'ref', 'title', 'watched_at'];
    }

    protected function rows(User $user): iterable
    {
        foreach ($this->entries->plays($user) as $play) {
            yield [...array_values($play->position->toArray()), $play->watchedAt->toIso8601String()];
        }
    }
}

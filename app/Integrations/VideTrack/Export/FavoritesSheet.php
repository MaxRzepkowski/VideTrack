<?php

namespace App\Integrations\VideTrack\Export;

use App\Integrations\Export\CsvExport;
use App\Integrations\Export\ExportShelf;
use App\Integrations\VideTrack\Backup\BackupEntries;
use App\Models\User;

/** Every favorite as a flat sheet. */
final class FavoritesSheet extends CsvExport
{
    public const CODE = 'favorites';

    public function __construct(private readonly BackupEntries $entries) {}

    public function code(): string
    {
        return self::CODE;
    }

    public function label(): string
    {
        return __('common.favorites');
    }

    public function shelf(): ExportShelf
    {
        return ExportShelf::Spreadsheet;
    }

    protected function header(): array
    {
        return ['type', 'provider', 'ref', 'title'];
    }

    protected function rows(User $user): iterable
    {
        foreach ($this->entries->favorites($user) as $favorite) {
            yield array_values($favorite->position->toArray());
        }
    }
}

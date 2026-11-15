<?php

namespace App\Integrations\VideTrack\Export;

use App\Integrations\Export\CsvExport;
use App\Integrations\Export\ExportShelf;
use App\Integrations\VideTrack\Backup\BackupEntries;
use App\Models\User;

/** Every position of every list, in list order, as a flat sheet. */
final class ListsSheet extends CsvExport
{
    public const CODE = 'lists';

    public function __construct(private readonly BackupEntries $entries) {}

    public function code(): string
    {
        return self::CODE;
    }

    public function label(): string
    {
        return __('common.lists');
    }

    public function shelf(): ExportShelf
    {
        return ExportShelf::Spreadsheet;
    }

    protected function header(): array
    {
        return ['list', 'visibility', 'position', 'type', 'provider', 'ref', 'title'];
    }

    protected function rows(User $user): iterable
    {
        foreach ($this->entries->lists($user) as $list) {
            foreach ($list->items as $position => $item) {
                $target = $item->target->toArray();

                yield [
                    $list->title,
                    $list->isPublic ? 'public' : 'private',
                    $position + 1,
                    $target['type'],
                    $target['provider'],
                    $target['ref'],
                    $target['title'],
                ];
            }
        }
    }
}

<?php

namespace App\Integrations\VideTrack\Export;

use App\Integrations\Export\ExportFormat;
use App\Integrations\Export\ExportShelf;
use App\Integrations\VideTrack\Backup\BackupWriter;
use App\Models\User;

/** The whole library in one file, the one a VideTrack import restores. */
final readonly class BackupExport implements ExportFormat
{
    public const CODE = 'videtrack';

    public function __construct(private BackupWriter $writer) {}

    public function code(): string
    {
        return self::CODE;
    }

    public function label(): string
    {
        return __('import.download_json');
    }

    public function shelf(): ExportShelf
    {
        return ExportShelf::Backup;
    }

    public function part(): ?string
    {
        return null;
    }

    public function extension(): string
    {
        return 'json';
    }

    public function contentType(): string
    {
        return 'application/json';
    }

    public function write(User $user, $out): void
    {
        $this->writer->write($user, $out);
    }
}

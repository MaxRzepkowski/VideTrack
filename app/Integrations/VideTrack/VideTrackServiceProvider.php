<?php

namespace App\Integrations\VideTrack;

use App\Integrations\Export\ExportFormatRegistry;
use App\Integrations\Import\ImportSourceRegistry;
use App\Integrations\VideTrack\Export\BackupExport;
use App\Integrations\VideTrack\Export\FavoritesSheet;
use App\Integrations\VideTrack\Export\HistorySheet;
use App\Integrations\VideTrack\Export\ListsSheet;
use App\Integrations\VideTrack\Export\RatingsSheet;
use Illuminate\Support\ServiceProvider;

/**
 * A VideTrack backup, written and restored, and the library as flat sheets.
 */
final class VideTrackServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->callAfterResolving(ImportSourceRegistry::class, fn (ImportSourceRegistry $sources) => $sources->register(VideTrackSource::CODE, VideTrackSource::class));

        $this->callAfterResolving(ExportFormatRegistry::class, function (ExportFormatRegistry $formats): void {
            $formats->register(BackupExport::CODE, BackupExport::class);
            $formats->register(HistorySheet::CODE, HistorySheet::class);
            $formats->register(RatingsSheet::CODE, RatingsSheet::class);
            $formats->register(FavoritesSheet::CODE, FavoritesSheet::class);
            $formats->register(ListsSheet::CODE, ListsSheet::class);
        });
    }
}

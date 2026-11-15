<?php

namespace App\Listeners;

use App\Events\Media\TitlePersisted;
use App\Events\Media\TitleRefreshed;
use App\Tasks\CatalogRefresh\RefreshQueue;

/**
 * A title stored or refreshed — by the background refresh, a manual
 * "Refresh" or its first save — was just answered for by its provider, so
 * the background refresh counts its wait from now.
 */
final readonly class RecordTitleAsked
{
    public function __construct(private RefreshQueue $queue) {}

    public function handle(TitlePersisted|TitleRefreshed $event): void
    {
        $this->queue->recordAsked($event->itemId);
    }
}

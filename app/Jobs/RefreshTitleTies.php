<?php

namespace App\Jobs;

use App\Actions\Media\ListFranchiseTitles;
use App\Actions\Media\RefreshTitleRelations;
use App\Catalog\ProviderCacheContext;
use App\Catalog\Relations\RelationStore;
use App\Models\MediaItem;
use App\Support\ExternalServiceException;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Asks the outside source about one title again, now, and lists its
 * franchises afresh: what a manual refresh of the title's page queues, so a
 * tie corrected at the source shows within minutes rather than at the
 * title's next turn months away. Off the request path: the source may take
 * most of a minute to answer. A title already waiting is not queued twice.
 */
class RefreshTitleTies implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, Queueable;

    public int $tries = 1;

    public int $timeout = 300;

    /** A worker that died mid-pass frees the title after this long. */
    public int $uniqueFor = 600;

    public function __construct(private readonly int $titleId) {}

    public function uniqueId(): string
    {
        return (string) $this->titleId;
    }

    public function handle(ProviderCacheContext $cache, RefreshTitleRelations $refresh, ListFranchiseTitles $listFranchises, RelationStore $store): void
    {
        $title = MediaItem::query()->find($this->titleId);

        if ($title === null) {
            return;
        }

        $locale = config()->string('tracker.default_locale');

        try {
            // Past the source's own day-long answer cache: the point is what it says now.
            $cache->fresh(function () use ($title, $locale, $refresh, $listFranchises, $store): void {
                $refresh->execute($title->newCollection([$title]), $locale);
                $listFranchises->execute($store->franchisesOf($title), $locale);
            });
        } catch (ExternalServiceException $e) {
            // The stored ties stand; the scheduled run asks again in its turn.
            report($e);
        }
    }
}

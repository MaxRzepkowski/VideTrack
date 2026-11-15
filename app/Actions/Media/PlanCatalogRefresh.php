<?php

namespace App\Actions\Media;

use App\Catalog\MediaTypeRegistry;
use App\Models\MediaItem;
use App\Tasks\CatalogRefresh\ChangeFeeds;
use App\Tasks\CatalogRefresh\RefreshQueue;
use App\Tasks\CatalogRefresh\RefreshReason;
use App\Tasks\CatalogRefresh\RefreshTally;
use App\Tasks\RefreshRoots;

/**
 * Marks what today's background refresh asks about: what the providers'
 * lists of changes name, the daily set (less the types a complete list
 * answered for today), then the day's turn of the cycle — from each provider's type, the share of its
 * roots that brings every one of them round within the cycle, longest
 * unasked first. A daily share, not an age threshold, spreads the work
 * evenly: neither the first run nor the one after a week off asks
 * everything at once.
 */
final readonly class PlanCatalogRefresh
{
    /** Days the cycle takes to ask about every stored root once, where nothing else watches a type. */
    public const CYCLE_DAYS = 7;

    /** The same where a complete list of changes watches the type: the cycle is only a safety net. */
    public const CYCLE_DAYS_WITH_FEED = 30;

    public function __construct(
        private ChangeFeeds $feeds,
        private RefreshRoots $roots,
        private RefreshQueue $queue,
        private MediaTypeRegistry $types,
    ) {}

    public function execute(): RefreshTally
    {
        $feeds = $this->feeds->read();

        $this->queue->markChanged($feeds->changedRootIds, $feeds->cursors);
        $this->queue->markDue($this->roots->activeIds($feeds->coveredTypes), RefreshReason::Active);

        foreach ($this->types->rootCodes() as $type) {
            $provider = $this->types->get($type)->provider;
            $roots = MediaItem::query()->whereNull('parent_id')->where('provider', $provider)->where('type', $type)->count();
            $days = in_array($type, $feeds->completeTypes, true) ? self::CYCLE_DAYS_WITH_FEED : self::CYCLE_DAYS;
            $share = (int) ceil($roots / $days);

            $this->queue->markDue($this->queue->longestUnasked($provider, $type, $share), RefreshReason::Cycle);
        }

        // A list that is not complete is measured against what the refresh
        // finds: whether it names every title that changed decides whether
        // it may ever stand in for asking.
        $measured = array_values(array_diff($feeds->answeredTypes, $feeds->completeTypes));

        return new RefreshTally(
            planned: $this->queue->dueByReason(),
            errors: $feeds->errors,
            measuredTypes: $measured,
            named: $feeds->changedRootIds,
        );
    }
}

<?php

namespace App\Tasks\Attention;

use App\Support\ServiceRegistry;
use App\Tasks\Attention\Contracts\AttentionSource;
use App\Tasks\Attention\Data\AttentionItem;
use App\Tasks\Attention\Data\PageVisit;
use App\Tasks\Maintenance\CheckLevel;

/**
 * What can need the admin's attention, registered by the parts of the
 * instance that know of it. One list for the overview and the home page.
 *
 * @extends ServiceRegistry<AttentionSource>
 */
final class AttentionRegistry extends ServiceRegistry
{
    protected function kind(): string
    {
        return 'attention source';
    }

    /**
     * Every item, problems first.
     *
     * @return list<AttentionItem>
     */
    public function items(PageVisit $visit): array
    {
        $items = array_merge(...array_map(fn (AttentionSource $source): array => $source->items($visit), $this->all()));

        usort($items, fn (AttentionItem $a, AttentionItem $b): int => ($b->level === CheckLevel::Problem) <=> ($a->level === CheckLevel::Problem));

        return $items;
    }

    /**
     * How many problems the home page tells the admin of: a warning waits on
     * the overview, where the admin may know why it stands.
     */
    public function problemCount(PageVisit $visit): int
    {
        return count(array_filter($this->items($visit), fn (AttentionItem $item): bool => $item->level === CheckLevel::Problem));
    }
}

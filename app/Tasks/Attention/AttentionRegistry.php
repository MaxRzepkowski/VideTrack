<?php

namespace App\Tasks\Attention;

use App\Support\ServiceRegistry;
use App\Tasks\Attention\Contracts\AttentionSource;
use App\Tasks\Attention\Data\AttentionItem;

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
     * @return list<AttentionItem>
     */
    public function items(): array
    {
        return array_merge(...array_map(fn (AttentionSource $source): array => $source->items(), $this->all()));
    }
}

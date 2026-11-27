<?php

namespace App\Tasks\Attention\Contracts;

use App\Tasks\Attention\Data\AttentionItem;

/**
 * One kind of trouble the admin has to fix themselves. The instance's
 * overview lists what every source says, and the home page counts it.
 */
interface AttentionSource
{
    /**
     * @return list<AttentionItem>
     */
    public function items(): array;
}

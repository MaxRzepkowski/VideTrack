<?php

namespace App\Integrations\Import\Rows;

use App\Integrations\Import\Data\ImportListRef;

/**
 * A list the input declares, so it exists after the run even when empty.
 */
final readonly class ListRow implements ImportRow
{
    public function __construct(public ImportListRef $list) {}
}

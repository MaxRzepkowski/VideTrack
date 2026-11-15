<?php

namespace App\Integrations\Import\Contracts;

use App\Integrations\Import\Data\SetupNote;

/**
 * A source that needs more than its one-line description: where to get the
 * file, what to type, what the service has to allow first.
 */
interface ExplainsSetup
{
    /**
     * Shown under the source once it is picked.
     *
     * @return list<SetupNote>
     */
    public function notes(): array;
}

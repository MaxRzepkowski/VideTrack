<?php

namespace App\Integrations\Import;

use App\Integrations\Import\Contracts\ReadsFromConnection;
use App\Integrations\Import\Data\ImportInput;
use App\Integrations\Import\Data\ImportManifest;
use App\Models\ServiceConnection;

/**
 * An import source that reads through a connection and offers the whole
 * account in one go: nothing is fetched to look, the run reads it all.
 */
abstract class ConnectionSource implements ReadsFromConnection
{
    public function analyze(ImportInput $input): ImportManifest
    {
        $connection = $this->connection($input);

        if ($connection === null || ! $connection->isActive()) {
            return ImportManifest::invalid(__('import.connect_your_account_first'));
        }

        return ImportManifest::whole([]);
    }

    /** The connection the run reads through, while it still exists. */
    protected function connection(ImportInput $input): ?ServiceConnection
    {
        return $input->connectionId === null ? null : ServiceConnection::query()->with('user')->find($input->connectionId);
    }
}

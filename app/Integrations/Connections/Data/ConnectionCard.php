<?php

namespace App\Integrations\Connections\Data;

use App\Integrations\Connections\ConnectionStatus;
use App\Models\ServiceConnection;
use App\Support\StoredReason;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * A connected account an import source reads through, as its card shows it.
 */
#[TypeScript]
final readonly class ConnectionCard
{
    public function __construct(
        public int $id,
        public string $service,
        public ?string $account,
        public ConnectionStatus $status,
        public bool $autoImport,
        public ?string $lastImportedAt,
        public ?string $lastError,
    ) {}

    public static function of(ServiceConnection $connection): self
    {
        return new self(
            id: $connection->id,
            service: $connection->service,
            account: $connection->account_label,
            status: $connection->status,
            autoImport: $connection->auto_import,
            lastImportedAt: $connection->last_imported_at?->toIso8601String(),
            lastError: StoredReason::worded($connection->last_error),
        );
    }
}

<?php

namespace App\Http\Data;

use App\Integrations\Import\Data\ConnectionSourceData;
use App\Integrations\Scrobble\Data\ScrobbleSourceData;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * The integrations page: every media server and every account source, and
 * which of them already feed the library (in the order they were set up)
 * and which can be added (by name).
 */
#[TypeScript]
final readonly class IntegrationsPage
{
    /**
     * @param  list<ScrobbleSourceData>  $webhooks
     * @param  list<ConnectionSourceData>  $accounts
     * @param  list<IntegrationRef>  $connected  servers with an address and accounts held, expired ones too
     * @param  list<IntegrationRef>  $available
     */
    public function __construct(
        public array $webhooks,
        public array $accounts,
        public array $connected,
        public array $available,
        /** The account source a trip to its service came back to, opened on arrival. */
        public ?string $selected,
    ) {}
}

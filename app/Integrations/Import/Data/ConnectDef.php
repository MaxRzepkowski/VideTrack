<?php

namespace App\Integrations\Import\Data;

use App\Integrations\Connections\ConnectionRegistry;
use App\Integrations\Connections\ConnectMethod;
use App\Integrations\Connections\Contracts\HasConnectionFields;
use App\Integrations\Connections\Data\ConnectionCard;
use App\Integrations\Connections\RedirectAttempts;
use App\Integrations\Import\Contracts\ReadsFromConnection;
use App\Models\ServiceConnection;
use App\Support\Forms\CredentialField;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * How an import source's card connects to its service (a code typed at the
 * service, or a pasted address and key) and the account it holds there.
 */
#[TypeScript]
final readonly class ConnectDef
{
    /**
     * @param  list<CredentialField>  $fields  what a form-authorised service asks for; none for a code flow
     */
    public function __construct(
        public string $service,
        public string $name,
        public ConnectMethod $method,
        public array $fields,
        public int $autoImportMinutes,
        public ?ConnectionCard $connection,
        /** Where the page holds why a trip to the service came back without connecting. */
        public string $errorKey,
    ) {}

    /** The card of a source reading through the service, with what the user already holds there. */
    public static function of(ReadsFromConnection $source, ConnectionRegistry $connections, ?ServiceConnection $held): self
    {
        $service = $source->connectionService();
        $connectable = $connections->get($service);

        return new self(
            service: $service,
            name: $connectable->name(),
            method: ConnectMethod::of($connectable),
            fields: $connectable instanceof HasConnectionFields ? $connectable->connectionFields() : [],
            autoImportMinutes: $source->autoImportMinutes(),
            connection: $held === null ? null : ConnectionCard::of($held),
            errorKey: RedirectAttempts::errorKey($service),
        );
    }
}

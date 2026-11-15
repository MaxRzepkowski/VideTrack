<?php

namespace App\Integrations\Import;

use App\Integrations\Import\Contracts\ImportSource;
use App\Integrations\Import\Contracts\ReadsCredentials;
use App\Integrations\Import\Contracts\ReadsFile;
use App\Integrations\Import\Contracts\ReadsFromConnection;
use LogicException;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * What the user hands over to start an import: an export file, a one-off
 * form (a public username), or a connection kept in Integrations.
 */
#[TypeScript]
enum ImportInputKind: string
{
    case File = 'file';
    case Credentials = 'credentials';
    case Connection = 'connection';

    /** The input a source reads, from the contract it implements. */
    public static function of(ImportSource $source): self
    {
        return match (true) {
            $source instanceof ReadsFile => self::File,
            $source instanceof ReadsCredentials => self::Credentials,
            $source instanceof ReadsFromConnection => self::Connection,
            default => throw new LogicException('Import source ['.$source->code().'] reads neither a file, a form nor a connection.'),
        };
    }
}

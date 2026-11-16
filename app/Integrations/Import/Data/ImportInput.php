<?php

namespace App\Integrations\Import\Data;

use App\Integrations\Import\ImportInputKind;
use Illuminate\Support\Facades\Crypt;

/**
 * What a run reads from. It travels in the job payload, so credentials are
 * sealed: a username is harmless, but the same slot carries keys too, and
 * the queue table is not the place for them in clear.
 */
final readonly class ImportInput
{
    private function __construct(
        public ImportInputKind $kind,
        public ?string $path = null,
        private ?string $sealedCredentials = null,
        public ?int $connectionId = null,
        public ?string $fileName = null,
    ) {}

    /**
     * An uploaded export at an absolute path; the run deletes it when done.
     * $fileName is what the user's file was called, for an export whose name
     * is all that names what it holds (a list saved as its own file).
     */
    public static function file(string $path, ?string $fileName = null): self
    {
        return new self(ImportInputKind::File, path: $path, fileName: $fileName);
    }

    /**
     * @param  array<string, string>  $values
     */
    public static function credentials(array $values): self
    {
        return new self(
            ImportInputKind::Credentials,
            sealedCredentials: Crypt::encryptString((string) json_encode($values)),
        );
    }

    public static function connection(int $connectionId): self
    {
        return new self(ImportInputKind::Connection, connectionId: $connectionId);
    }

    public function credential(string $key): string
    {
        if ($this->sealedCredentials === null) {
            return '';
        }

        $values = json_decode(Crypt::decryptString($this->sealedCredentials), true);

        return is_array($values) && is_string($values[$key] ?? null) ? $values[$key] : '';
    }
}

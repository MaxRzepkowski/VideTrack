<?php

namespace App\Integrations\Import\Contracts;

use App\Integrations\Import\Data\CredentialField;

/** An import source whose input is a one-off form: a public username, a profile address. */
interface ReadsCredentials extends ImportSource
{
    /**
     * The fields of the form.
     *
     * @return list<CredentialField>
     */
    public function credentialsSchema(): array;
}

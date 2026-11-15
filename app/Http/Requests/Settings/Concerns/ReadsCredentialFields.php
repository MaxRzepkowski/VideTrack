<?php

namespace App\Http\Requests\Settings\Concerns;

use App\Integrations\Import\Contracts\ReadsCredentials;
use App\Integrations\Import\Data\ImportInput;

/**
 * The fields of a credentials source's form, as a request validates them
 * and as the run gets them: text trimmed, an opt-in as '1' or ''.
 */
trait ReadsCredentialFields
{
    /**
     * @return array<string, list<string>>
     */
    private function credentialRules(ReadsCredentials $source): array
    {
        $rules = [];

        foreach ($source->credentialsSchema() as $field) {
            $rules["credentials.{$field->key}"] = $field->required()
                ? ['required', 'string', 'max:255']
                : ['nullable', 'boolean'];
        }

        return $rules;
    }

    private function credentialInput(ReadsCredentials $source): ImportInput
    {
        $values = [];

        foreach ($source->credentialsSchema() as $field) {
            $key = "credentials.{$field->key}";

            $values[$field->key] = $field->required()
                ? $this->string($key)->trim()->value()
                : ($this->boolean($key) ? '1' : '');
        }

        return ImportInput::credentials($values);
    }
}

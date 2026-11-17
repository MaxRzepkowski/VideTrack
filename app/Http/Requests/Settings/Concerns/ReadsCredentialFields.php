<?php

namespace App\Http\Requests\Settings\Concerns;

use App\Integrations\Import\Contracts\ReadsCredentials;
use App\Integrations\Import\Data\ImportInput;
use App\Support\Forms\CredentialField;
use App\Support\Forms\CredentialFieldType;

/**
 * The fields of a form a module describes (CredentialField), as a request
 * validates them and as the module gets them: text trimmed, an opt-in as
 * '1' or ''. Each form keeps its fields under a key of its own.
 */
trait ReadsCredentialFields
{
    /** How long an import source's credential may be. */
    private const CREDENTIAL_MAX = 255;

    /**
     * @param  list<CredentialField>  $fields
     * @return array<string, list<string>>
     */
    private function fieldRules(array $fields, string $prefix, int $max): array
    {
        $rules = [];

        foreach ($fields as $field) {
            $rules["{$prefix}.{$field->key}"] = match (true) {
                $field->type === CredentialFieldType::Checkbox => ['nullable', 'boolean'],
                $field->required() => ['required', 'string', "max:{$max}"],
                default => ['nullable', 'string', "max:{$max}"],
            };
        }

        return $rules;
    }

    /**
     * @param  list<CredentialField>  $fields
     * @return array<string, string> field key => value
     */
    private function fieldValues(array $fields, string $prefix): array
    {
        $values = [];

        foreach ($fields as $field) {
            $key = "{$prefix}.{$field->key}";

            $values[$field->key] = $field->type === CredentialFieldType::Checkbox
                ? ($this->boolean($key) ? '1' : '')
                : $this->string($key)->trim()->value();
        }

        return $values;
    }

    /**
     * @return array<string, list<string>>
     */
    private function credentialRules(ReadsCredentials $source): array
    {
        return $this->fieldRules($source->credentialsSchema(), 'credentials', self::CREDENTIAL_MAX);
    }

    private function credentialInput(ReadsCredentials $source): ImportInput
    {
        return ImportInput::credentials($this->fieldValues($source->credentialsSchema(), 'credentials'));
    }
}

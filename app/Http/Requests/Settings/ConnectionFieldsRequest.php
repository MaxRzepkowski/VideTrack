<?php

namespace App\Http\Requests\Settings;

use App\Http\Requests\Settings\Concerns\ReadsCredentialFields;
use App\Integrations\Connections\ConnectionRegistry;
use App\Integrations\Connections\Contracts\HasConnectionFields;
use App\Integrations\Connections\UnusableAddress;
use App\Integrations\Connections\UserAddresses;
use App\Models\User;
use App\Support\Forms\CredentialFieldType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * What the user typed on a service's card before connecting: the service's
 * own fields, all required and validated in one go so every missing one is
 * named at once. Every address field is checked by `UserAddresses` here, so
 * a refused address is named under its field before the service is asked.
 * This is the early answer, not the protection: the service's client pins
 * the address it calls and follows no redirect.
 */
abstract class ConnectionFieldsRequest extends FormRequest
{
    use ReadsCredentialFields;

    /** A sign-in value may be a long address or token. */
    private const VALUE_MAX = 2048;

    private ?HasConnectionFields $connectable = null;

    /**
     * How the service must connect for this request to be its own; one
     * that connects some other way is no page here.
     *
     * @return class-string<HasConnectionFields>
     */
    abstract protected function capability(): string;

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return $this->fieldRules($this->connectable()->connectionFields(), 'values', self::VALUE_MAX);
    }

    /**
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $owner = $this->user();

            if (! $owner instanceof User || $validator->errors()->isNotEmpty()) {
                return;
            }

            foreach ($this->connectable()->connectionFields() as $field) {
                if ($field->type !== CredentialFieldType::Url) {
                    continue;
                }

                try {
                    app(UserAddresses::class)->pin($this->string("values.{$field->key}")->trim()->value(), $owner);
                } catch (UnusableAddress $unusable) {
                    $validator->errors()->add("values.{$field->key}", $unusable->wording());
                }
            }
        }];
    }

    /**
     * The card's fields, trimmed.
     *
     * @return array<string, string>
     */
    public function values(): array
    {
        return $this->fieldValues($this->connectable()->connectionFields(), 'values');
    }

    /** The service the route names, connecting the way this request is for. */
    protected function connectable(): HasConnectionFields
    {
        if ($this->connectable === null) {
            $service = $this->route('service');

            $this->connectable = (is_string($service) ? app(ConnectionRegistry::class)->capable($service, $this->capability()) : null) ?? abort(404);
        }

        return $this->connectable;
    }
}

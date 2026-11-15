<?php

namespace App\Http\Requests\Settings;

use App\Integrations\Connections\ConnectionRegistry;
use App\Integrations\Connections\Contracts\AuthorizesByForm;
use App\Integrations\Connections\UnusableAddress;
use App\Integrations\Connections\UserAddresses;
use App\Integrations\Import\Data\CredentialFieldType;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * What the user typed into a service's connect form: the service's own
 * fields, all required and validated in one go so every missing one is named
 * at once, plus the account picked from what verifying listed. Every
 * address field is checked by `UserAddresses` here, so a refused address is
 * named under its field before the service is asked. This is the early
 * answer, not the protection: the service's client pins the address it
 * calls and follows no redirect.
 */
class ConnectionFormRequest extends FormRequest
{
    private ?AuthorizesByForm $connectable = null;

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        $rules = ['choice' => ['nullable', 'string', 'max:255']];

        foreach ($this->connectable()->connectionFields() as $field) {
            $rules["values.{$field->key}"] = ['required', 'string', 'max:2048'];
        }

        return $rules;
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

    /** The service the route names; one that connects by no form is no page here. */
    public function connectable(): AuthorizesByForm
    {
        if ($this->connectable === null) {
            $registry = app(ConnectionRegistry::class);
            $service = $this->route('service');
            $connectable = is_string($service) && $registry->has($service) ? $registry->get($service) : null;

            abort_unless($connectable instanceof AuthorizesByForm, 404);

            $this->connectable = $connectable;
        }

        return $this->connectable;
    }

    /**
     * The form's fields, trimmed.
     *
     * @return array<string, string>
     */
    public function values(): array
    {
        $values = [];

        foreach ($this->connectable()->connectionFields() as $field) {
            $values[$field->key] = $this->string("values.{$field->key}")->trim()->value();
        }

        return $values;
    }

    /** The account picked from the verified list; null where the service lists none. */
    public function choice(): ?string
    {
        return $this->string('choice')->value() ?: null;
    }
}

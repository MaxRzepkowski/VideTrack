<?php

namespace App\Http\Requests\Settings;

use App\Http\Requests\Settings\Concerns\ReadsCredentialFields;
use App\Integrations\Connections\ConnectionRegistry;
use App\Integrations\Connections\Contracts\AuthorizesByForm;
use App\Integrations\Connections\UnusableAddress;
use App\Integrations\Connections\UserAddresses;
use App\Models\User;
use App\Support\Forms\CredentialFieldType;
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
    use ReadsCredentialFields;

    /** A sign-in value may be a long address or token. */
    private const VALUE_MAX = 2048;

    private ?AuthorizesByForm $connectable = null;

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'choice' => ['nullable', 'string', 'max:255'],
            ...$this->fieldRules($this->connectable()->connectionFields(), 'values', self::VALUE_MAX),
        ];
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
            $service = $this->route('service');

            $this->connectable = (is_string($service) ? app(ConnectionRegistry::class)->capable($service, AuthorizesByForm::class) : null) ?? abort(404);
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
        return $this->fieldValues($this->connectable()->connectionFields(), 'values');
    }

    /** The account picked from the verified list; null where the service lists none. */
    public function choice(): ?string
    {
        return $this->string('choice')->value() ?: null;
    }
}

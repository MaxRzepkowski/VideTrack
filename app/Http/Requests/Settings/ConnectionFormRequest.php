<?php

namespace App\Http\Requests\Settings;

use App\Integrations\Connections\Contracts\AuthorizesByForm;
use LogicException;

/**
 * What the user typed into a service's connect form (an address and a key)
 * and the account picked from what verifying listed.
 */
class ConnectionFormRequest extends ConnectionFieldsRequest
{
    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return ['choice' => ['nullable', 'string', 'max:255'], ...parent::rules()];
    }

    /** The service, connected by the form itself. */
    public function form(): AuthorizesByForm
    {
        $connectable = $this->connectable();

        return $connectable instanceof AuthorizesByForm ? $connectable : throw new LogicException('A connect form for a service that connects no form.');
    }

    /** The account picked from the verified list; null where the service lists none. */
    public function choice(): ?string
    {
        return $this->string('choice')->value() ?: null;
    }

    protected function capability(): string
    {
        return AuthorizesByForm::class;
    }
}

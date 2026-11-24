<?php

namespace App\Http\Requests\Settings;

use App\Http\Requests\Settings\Concerns\ConfirmsAccount;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Someone confirming what cannot be undone (deleting the account, clearing
 * its data).
 */
class ConfirmAccountRequest extends FormRequest
{
    use ConfirmsAccount;

    /**
     * @return array<string, mixed>
     */
    public function rules(#[CurrentUser] User $viewer): array
    {
        return $this->confirmationRules($viewer, 'password', 'username');
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return $this->confirmationMessages('username');
    }
}

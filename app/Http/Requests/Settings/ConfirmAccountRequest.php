<?php

namespace App\Http\Requests\Settings;

use App\Accounts\PasswordLogin;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use LogicException;

/**
 * Someone confirming what cannot be undone (deleting the account, clearing
 * its data): with their password where it is their way in, otherwise by
 * typing their username.
 */
class ConfirmAccountRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $user = $this->user();

        if (! $user instanceof User) {
            throw new LogicException('Only a signed-in account confirms.');
        }

        return app(PasswordLogin::class)->isWayInFor($user)
            ? ['password' => ['required', 'current_password']]
            : ['username' => ['required', 'string', Rule::in([$user->username])]];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['username.in' => trans('settings.username_does_not_match')];
    }
}

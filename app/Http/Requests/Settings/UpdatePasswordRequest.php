<?php

namespace App\Http\Requests\Settings;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

/**
 * A new password. An account made through an identity provider sets its
 * first one without a current password to confirm.
 */
class UpdatePasswordRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $user = $this->user();

        return [
            ...($user instanceof User && $user->hasPassword() ? ['current_password' => ['required', 'current_password']] : []),
            'password' => ['required', Password::defaults(), 'confirmed'],
        ];
    }

    public function password(): string
    {
        return $this->string('password')->toString();
    }
}

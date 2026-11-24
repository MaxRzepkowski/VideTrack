<?php

namespace App\Http\Requests\Settings;

use App\Accounts\Username;
use App\Http\Requests\Settings\Concerns\ConfirmsAccount;
use App\Models\User;
use App\Support\Locales;
use App\Support\Payload;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProfileUpdateRequest extends FormRequest
{
    use ConfirmsAccount;

    /** What confirms a new address: the password, or the username without one. */
    private const CONFIRM_PASSWORD = 'current_password';

    private const CONFIRM_USERNAME = 'confirm_username';

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(#[CurrentUser] User $viewer): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'username' => ['required', ...Username::rules(), Rule::unique(User::class)->ignore($viewer->id)],
            'bio' => ['nullable', 'string', 'max:500'],
            'is_private' => ['sometimes', 'boolean'],
            // Bounded so decoding it for re-encoding fits the worker's memory.
            'avatar' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048', 'dimensions:max_width=4096,max_height=4096'],
            'remove_avatar' => ['sometimes', 'boolean'],
            'email' => [
                'required',
                'string',
                'lowercase',
                'email',
                'max:255',
                Rule::unique(User::class)->ignore($viewer->id),
            ],
            'locale' => ['sometimes', 'string', Rule::in(Locales::supported())],
            // Password resets go to this address: whoever changes it holds the
            // account, so a session left open is not enough.
            ...($this->changesEmail($viewer) ? $this->confirmationRules($viewer, self::CONFIRM_PASSWORD, self::CONFIRM_USERNAME) : []),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return $this->confirmationMessages(self::CONFIRM_USERNAME);
    }

    /**
     * The account's own fields as validated; the avatar is stored apart.
     *
     * @return array<string, mixed>
     */
    public function profile(): array
    {
        return Payload::named($this->safe()->except(['avatar', 'remove_avatar', self::CONFIRM_PASSWORD, self::CONFIRM_USERNAME]));
    }

    private function changesEmail(User $viewer): bool
    {
        return $this->string('email')->trim()->lower()->value() !== $viewer->email;
    }
}

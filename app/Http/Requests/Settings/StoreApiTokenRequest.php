<?php

namespace App\Http\Requests\Settings;

use App\Accounts\UserCalendar;
use App\Models\User;
use App\Support\StoredTime;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use LogicException;

class StoreApiTokenRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:60'],
            // Read is implied — a token that cannot read is of no use.
            'write' => ['sometimes', 'boolean'],
            // After the viewer's today: past their midnight it is already tomorrow there.
            'expires_at' => ['nullable', 'date_format:Y-m-d', 'after:'.UserCalendar::of($this->viewer())->today()->toDateString()],
        ];
    }

    private function viewer(): User
    {
        return $this->user() instanceof User ? $this->user() : throw new LogicException('A token is only ever made by a signed-in user.');
    }

    public function name(): string
    {
        return $this->string('name')->toString();
    }

    /** @return list<string> */
    public function abilities(): array
    {
        return $this->boolean('write') ? ['read', 'write'] : ['read'];
    }

    /**
     * The picked day is a day on the user's own calendar: the token lasts
     * until it ends there, not where the server keeps its clock.
     */
    public function expiresAt(): ?CarbonImmutable
    {
        $day = $this->string('expires_at')->toString();

        return $day === '' ? null : StoredTime::of(UserCalendar::of($this->viewer())->endOf($day));
    }
}

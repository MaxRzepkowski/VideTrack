<?php

namespace App\Http\Requests\Settings;

use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;

/** A browser's new subscription, and the address of the one it replaces. */
class RenewPushDeviceRequest extends PushDeviceRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(#[CurrentUser] User $viewer): array
    {
        return [...parent::rules($viewer), 'previous_endpoint' => ['required', 'string', 'max:'.self::ENDPOINT_MAX]];
    }

    public function previousEndpoint(): string
    {
        return $this->string('previous_endpoint')->value();
    }
}

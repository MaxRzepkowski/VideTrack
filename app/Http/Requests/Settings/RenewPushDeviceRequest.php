<?php

namespace App\Http\Requests\Settings;

/** A browser's new subscription, and the address of the one it replaces. */
class RenewPushDeviceRequest extends PushDeviceRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [...parent::rules(), 'previous_endpoint' => ['required', 'string', 'max:'.self::ENDPOINT_MAX]];
    }

    public function previousEndpoint(): string
    {
        return $this->string('previous_endpoint')->value();
    }
}

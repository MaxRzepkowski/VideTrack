<?php

namespace App\Http\Requests\Settings;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The part of an endpoint the user owns: what to call it, whether it is
 * listening, and whose playback on the server counts as theirs.
 */
class UpdateWebhookEndpointRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'label' => ['nullable', 'string', 'max:60'],
            'enabled' => ['required', 'boolean'],
            // Empty means "whoever plays it", which is what a server with
            // one person on it wants.
            'server_username' => ['nullable', 'string', 'max:100'],
        ];
    }
}

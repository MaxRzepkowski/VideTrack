<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\WebhookEndpoint;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<WebhookEndpoint>
 */
class WebhookEndpointFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $token = Str::random(48);

        return [
            'user_id' => User::factory(),
            'source' => 'jellyfin',
            // Issued with the endpoint, as IssueWebhookToken does.
            'token' => $token,
            'token_hash' => WebhookEndpoint::tokenHash($token),
        ];
    }
}

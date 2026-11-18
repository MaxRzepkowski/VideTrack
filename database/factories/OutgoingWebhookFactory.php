<?php

namespace Database\Factories;

use App\Integrations\OutgoingWebhooks\WebhookEventGroup;
use App\Integrations\OutgoingWebhooks\WebhookSignature;
use App\Models\OutgoingWebhook;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OutgoingWebhook>
 */
class OutgoingWebhookFactory extends Factory
{
    public const URL = 'http://n8n.example.com/webhook/abc';

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'label' => 'n8n',
            'url' => self::URL,
            'secret' => WebhookSignature::newSecret(),
            'events' => WebhookEventGroup::cases(),
        ];
    }

    public function disabled(): static
    {
        return $this->state(['enabled' => false]);
    }

    /**
     * @param  list<WebhookEventGroup>  $groups
     */
    public function listeningTo(array $groups): static
    {
        return $this->state(['events' => $groups]);
    }
}

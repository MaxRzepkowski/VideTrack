<?php

namespace Database\Factories;

use App\Integrations\OutgoingWebhooks\WebhookEventGroup;
use App\Models\OutgoingWebhook;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OutgoingWebhook>
 */
class OutgoingWebhookFactory extends Factory
{
    /** The secret of the vector the Standard Webhooks libraries test themselves against: public, no real secret. */
    public const SECRET = 'whsec_MfKQ9r8GKYqrTwjUPD8ILPZIo2LaLaSw'; // gitleaks:allow

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
            'secret' => self::SECRET,
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

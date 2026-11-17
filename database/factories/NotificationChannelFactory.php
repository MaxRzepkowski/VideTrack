<?php

namespace Database\Factories;

use App\Integrations\Discord\DiscordChannel;
use App\Models\NotificationChannel;
use App\Models\User;
use App\Notifications\Channels\ChannelMode;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<NotificationChannel>
 */
class NotificationChannelFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'driver' => DiscordChannel::CODE,
            'kind' => DiscordChannel::CODE,
            'label' => 'Discord',
            'config' => [DiscordChannel::WEBHOOK => 'https://discord.com/api/webhooks/123/abcd'],
            'mode' => ChannelMode::Digest,
        ];
    }

    public function instant(): static
    {
        return $this->state(['mode' => ChannelMode::Instant]);
    }

    public function disabled(): static
    {
        return $this->state(['enabled' => false]);
    }
}

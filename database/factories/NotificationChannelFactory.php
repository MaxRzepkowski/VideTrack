<?php

namespace Database\Factories;

use App\Integrations\Discord\DiscordChannel;
use App\Integrations\WebPush\WebPushChannel;
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

    /** A web push channel, as the first browser to subscribe makes it. */
    public function webPush(): static
    {
        return $this->instant()->state([
            'driver' => WebPushChannel::CODE,
            'kind' => WebPushChannel::CODE,
            'label' => 'Browser notifications',
            'config' => [],
        ]);
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

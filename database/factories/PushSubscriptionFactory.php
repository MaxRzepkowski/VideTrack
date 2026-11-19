<?php

namespace Database\Factories;

use App\Models\NotificationChannel;
use App\Models\PushSubscription;
use App\Support\Payload;
use Base64Url\Base64Url;
use Illuminate\Database\Eloquent\Factories\Factory;
use Minishlink\WebPush\ContentEncoding;

/**
 * A browser subscribed to a web push channel of its own. The keys are only
 * the right shape: a test that encrypts a message gives real ones.
 *
 * @extends Factory<PushSubscription>
 */
class PushSubscriptionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'notification_channel_id' => NotificationChannel::factory()->webPush(),
            'endpoint' => 'https://push.example.com/send/'.$this->faker->unique()->uuid(),
            // Follows an endpoint the test gives.
            'endpoint_hash' => fn (array $attributes): string => PushSubscription::hashOf(Payload::text($attributes, 'endpoint')),
            // A P-256 point is 65 bytes, an auth secret 16, both base64url.
            'public_key' => Base64Url::encode(random_bytes(65)),
            'auth_token' => Base64Url::encode(random_bytes(16)),
            'content_encoding' => ContentEncoding::aes128gcm,
            'vapid_key' => 'the-instance-key',
            'user_agent' => 'Mozilla/5.0 (X11; Linux x86_64; rv:140.0) Gecko/20100101 Firefox/140.0',
        ];
    }
}

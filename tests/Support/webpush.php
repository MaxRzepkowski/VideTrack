<?php

use App\Integrations\WebPush\VapidCredentials;
use App\Integrations\WebPush\VapidKeys;
use App\Integrations\WebPush\WebPushChannel;
use App\Models\NotificationChannel;
use App\Models\PushSubscription;
use App\Models\User;
use Base64Url\Base64Url;
use Minishlink\WebPush\VAPID;

// Web push with real keys: the instance's pair, and a browser's to encrypt to.

/** Gives the instance a key pair, as its first start would. */
function withWebPushKeys(): VapidCredentials
{
    $pair = VAPID::createVapidKeys();
    config(['tracker.webpush.public_key' => $pair['publicKey'], 'tracker.webpush.private_key' => $pair['privateKey']]);

    return app(VapidKeys::class)->current() ?? throw new LogicException('The keys were just given.');
}

/**
 * The keys a browser hands over when it subscribes: a P-256 public key and
 * an auth secret, base64url.
 *
 * @return array{public_key: string, auth_token: string}
 */
function browserPushKeys(): array
{
    $key = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
    $details = $key === false ? false : openssl_pkey_get_details($key);

    if ($details === false) {
        throw new LogicException('OpenSSL makes P-256 keys.');
    }

    $point = "\x04".str_pad($details['ec']['x'], 32, "\0", STR_PAD_LEFT).str_pad($details['ec']['y'], 32, "\0", STR_PAD_LEFT);

    return ['public_key' => Base64Url::encode($point), 'auth_token' => Base64Url::encode(random_bytes(16))];
}

/**
 * The body a browser posts to switch push on, as the page sends it.
 *
 * @return array<string, string>
 */
function browserSubscription(string $endpoint = 'https://push.example.com/send/one'): array
{
    return ['endpoint' => $endpoint, ...browserPushKeys(), 'content_encoding' => 'aes128gcm'];
}

/** A browser of the user's, subscribed with the instance's keys; the channel comes with the first. */
function pushDevice(User $user, VapidCredentials $vapid, string $endpoint = 'https://push.example.com/send/one'): PushSubscription
{
    $channel = $user->notificationChannels()->where('driver', WebPushChannel::CODE)->first()
        ?? NotificationChannel::factory()->for($user)->webPush()->create();

    return PushSubscription::factory()->for($channel, 'channel')->create([...browserPushKeys(), 'endpoint' => $endpoint, 'vapid_key' => $vapid->publicKey]);
}

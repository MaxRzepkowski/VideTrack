<?php

namespace App\Integrations\WebPush;

use App\Actions\Users\UnsubscribeDevice;
use App\Integrations\WebPush\Data\WebPushMessage;
use App\Models\NotificationChannel;
use App\Models\PushSubscription;
use App\Models\User;
use App\Notifications\Channels\Contracts\ChannelDriver;
use App\Notifications\Channels\Data\ChannelDigest;
use App\Notifications\Channels\Data\ChannelKind;
use App\Notifications\Channels\Data\ChannelMessage;
use App\Support\Delivery\DeliveryAnswer;

/**
 * Notifications shown by the browser or the installed app, on every device
 * the user switched them on for. The channel keeps nothing of its own: it
 * comes with the first device and goes with the last, so the form never
 * offers it. A browser that stopped listening is forgotten on the spot.
 */
final readonly class WebPushChannel implements ChannelDriver
{
    public const CODE = 'webpush';

    public function __construct(
        private WebPushClient $client,
        private VapidKeys $keys,
        private UnsubscribeDevice $unsubscribe,
        private WebPushMessages $messages,
    ) {}

    public function kinds(): array
    {
        return [new ChannelKind(code: self::CODE, label: __('settings.browser_notifications'), fields: [], fromDevices: true)];
    }

    public function rules(string $kind): array
    {
        return [];
    }

    public function configure(string $kind, array $values, User $owner): array
    {
        return [];
    }

    public function deliver(ChannelMessage $message, NotificationChannel $channel): DeliveryAnswer
    {
        return $this->send($channel, $this->messages->message($message));
    }

    public function deliverDigest(ChannelDigest $digest, NotificationChannel $channel): array
    {
        return [$this->send($channel, $this->messages->digest($digest))];
    }

    /**
     * Arrived on one browser counts as delivered: a retry would show it there
     * twice. Otherwise a passing failure is worth the retry, and with no
     * browser left to listen the channel's address is gone.
     */
    private function send(NotificationChannel $channel, WebPushMessage $message): DeliveryAnswer
    {
        $vapid = $this->keys->current();

        if ($vapid === null) {
            return DeliveryAnswer::Unavailable;
        }

        $answers = [];
        $owner = $channel->loadMissing('user')->user;

        // Read per channel: the core knows no devices to load up front, and each browser costs a request anyway.
        foreach (PushSubscription::query()->ofChannel($channel->id)->get() as $subscription) {
            // Subscribed with a pair the instance no longer signs with: the push service would refuse it.
            if ($subscription->isStale($vapid->publicKey)) {
                continue;
            }

            $answers[] = $answer = $this->client->send($subscription, $message, $vapid, $owner);

            // No browser listens there, or none the instance may call.
            if ($answer === DeliveryAnswer::Gone || $answer === DeliveryAnswer::NotAllowed) {
                $this->unsubscribe->execute($subscription);
            }
        }

        return match (true) {
            $answers === [] => DeliveryAnswer::Gone,
            in_array(DeliveryAnswer::Delivered, $answers, true) => DeliveryAnswer::Delivered,
            default => array_find($answers, fn (DeliveryAnswer $answer): bool => $answer->isTemporary()) ?? $answers[0],
        };
    }
}

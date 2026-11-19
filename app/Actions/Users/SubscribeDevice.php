<?php

namespace App\Actions\Users;

use App\Integrations\WebPush\Data\BrowserSubscription;
use App\Integrations\WebPush\VapidKeys;
use App\Integrations\WebPush\WebPushChannel;
use App\Models\PushSubscription;
use App\Models\User;
use App\Notifications\Channels\ChannelMode;
use App\Notifications\Channels\ChannelRegistry;
use App\Notifications\Channels\Data\ChannelSettings;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * A browser gets the user's notifications from now on. The first one brings
 * the web push channel, delivering the moment a notification is written: a
 * notification on the device is what the user switched on. A browser
 * subscribed again keeps its row with the new keys; one another account
 * subscribed before moves to this user, who is the one at it now.
 */
final readonly class SubscribeDevice
{
    public function __construct(
        private ChannelRegistry $drivers,
        private VapidKeys $keys,
        private AddChannel $addChannel,
        private UnsubscribeDevice $unsubscribe,
    ) {}

    public function execute(User $user, BrowserSubscription $browser): PushSubscription
    {
        $vapid = $this->keys->current() ?? throw new LogicException('A browser subscribes only on an instance with push.');
        $kind = $this->drivers->kindOf(WebPushChannel::CODE, WebPushChannel::CODE) ?? throw new LogicException('An instance with push offers its channel.');

        return DB::transaction(function () use ($user, $browser, $vapid, $kind): PushSubscription {
            $channel = $user->notificationChannels()->where('driver', WebPushChannel::CODE)->orderBy('id')->first()
                ?? $this->addChannel->execute($user, WebPushChannel::CODE, $kind, new ChannelSettings(null, ChannelMode::Instant, null, []));

            $previous = PushSubscription::query()->ofEndpoint($browser->endpoint)->first();

            if ($previous !== null && $previous->notification_channel_id !== $channel->id) {
                $this->unsubscribe->execute($previous);
            }

            return PushSubscription::query()->updateOrCreate(
                ['endpoint_hash' => PushSubscription::hashOf($browser->endpoint)],
                [
                    'notification_channel_id' => $channel->id,
                    'endpoint' => $browser->endpoint,
                    'public_key' => $browser->publicKey,
                    'auth_token' => $browser->authToken,
                    'content_encoding' => $browser->contentEncoding,
                    'vapid_key' => $vapid->publicKey,
                    'user_agent' => $browser->userAgent,
                ],
            );
        });
    }
}

<?php

namespace App\Actions\Users;

use App\Integrations\WebPush\Data\BrowserSubscription;
use App\Models\PushSubscription;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * A browser of the user's subscribed again on its own, after the instance's
 * key pair changed: its new subscription takes the old one's place. Null
 * when the old one was not the user's, so nobody's browser is taken over
 * without a click.
 */
final readonly class RenewDevice
{
    public function __construct(
        private SubscribeDevice $subscribe,
        private UnsubscribeDevice $unsubscribe,
    ) {}

    public function execute(User $user, string $previousEndpoint, BrowserSubscription $browser): ?PushSubscription
    {
        $previous = PushSubscription::query()
            ->ofEndpoint($previousEndpoint)
            ->whereRelation('channel', 'user_id', $user->id)
            ->first();

        if ($previous === null) {
            return null;
        }

        return DB::transaction(function () use ($user, $previous, $browser): PushSubscription {
            $device = $this->subscribe->execute($user, $browser);

            if ($previous->isNot($device)) {
                $this->unsubscribe->execute($previous);
            }

            return $device;
        });
    }
}

<?php

namespace App\Actions\Users;

use App\Models\NotificationChannel;
use App\Models\PushSubscription;
use Illuminate\Support\Facades\DB;

/**
 * A browser no longer gets the user's notifications: removed by the user, or
 * gone at its push service. The web push channel goes with its last browser.
 */
final readonly class UnsubscribeDevice
{
    public function execute(PushSubscription $device): void
    {
        DB::transaction(function () use ($device): void {
            $device->delete();

            if (! PushSubscription::query()->ofChannel($device->notification_channel_id)->exists()) {
                NotificationChannel::query()->whereKey($device->notification_channel_id)->delete();
            }
        });
    }
}

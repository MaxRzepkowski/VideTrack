<?php

namespace App\Integrations\WebPush;

use App\Integrations\WebPush\Data\PushDeviceData;
use App\Integrations\WebPush\Data\PushDevicesData;
use App\Models\PushSubscription;
use App\Models\User;

/** The browsers a user's notifications are pushed to, for the settings page. */
final readonly class PushDevices
{
    public function __construct(private VapidKeys $keys) {}

    /** Null on an instance without push. */
    public function of(User $user): ?PushDevicesData
    {
        $vapid = $this->keys->current();

        if ($vapid === null) {
            return null;
        }

        $devices = PushSubscription::query()
            ->whereRelation('channel', 'user_id', $user->id)
            ->orderBy('id')
            ->get()
            ->map(fn (PushSubscription $device): PushDeviceData => new PushDeviceData(
                id: $device->id,
                userAgent: $device->user_agent,
                fingerprint: $device->endpoint_hash,
                subscribedAt: $device->created_at->toIso8601String(),
                stale: $device->isStale($vapid->publicKey),
            ));

        return new PushDevicesData($vapid->publicKey, array_values($devices->all()));
    }
}

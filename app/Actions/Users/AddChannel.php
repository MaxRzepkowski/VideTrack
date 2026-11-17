<?php

namespace App\Actions\Users;

use App\Models\NotificationChannel;
use App\Models\User;
use App\Notifications\Channels\ChannelMode;
use App\Notifications\Channels\Data\ChannelKind;
use App\Notifications\Channels\Data\ChannelSettings;
use InvalidArgumentException;

/** A new place the user's notifications go to, named after its kind and in the default mode unless told otherwise. */
final readonly class AddChannel
{
    public function execute(User $user, string $driver, ChannelKind $kind, ChannelSettings $settings): NotificationChannel
    {
        return $user->notificationChannels()->create([
            'driver' => $driver,
            'kind' => $kind->code,
            'label' => $settings->label === null || $settings->label === '' ? $kind->label : $settings->label,
            'config' => $settings->config ?? throw new InvalidArgumentException('A new channel needs its settings.'),
            'mode' => $settings->mode ?? ChannelMode::default(),
            ...($settings->enabled === null ? [] : ['enabled' => $settings->enabled]),
        ]);
    }
}

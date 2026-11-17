<?php

namespace App\Actions\Users;

use App\Models\NotificationChannel;
use App\Notifications\Channels\Data\ChannelKind;
use App\Notifications\Channels\Data\ChannelSettings;

/**
 * A channel's name, mode, switch or address changed; what was not sent stays
 * as saved. A name left empty goes back to the kind's, and a new address
 * replaces the old one whole and forgets how it failed.
 */
final readonly class UpdateChannel
{
    /**
     * @param  ChannelKind|null  $kind  null while the instance cannot list it: an emptied name then stays as it was
     */
    public function execute(NotificationChannel $channel, ?ChannelKind $kind, ChannelSettings $settings): void
    {
        if ($settings->label !== null) {
            $channel->label = $settings->label === '' ? $kind->label ?? $channel->label : $settings->label;
        }

        $channel->mode = $settings->mode ?? $channel->mode;
        $channel->enabled = $settings->enabled ?? $channel->enabled;

        if ($settings->config !== null) {
            $channel->config = $settings->config;
            $channel->last_failure = null;
        }

        $channel->save();
    }
}

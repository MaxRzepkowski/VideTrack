<?php

namespace App\Actions\Users;

use App\Models\NotificationChannel;
use App\Notifications\Channels\ChannelMessages;
use App\Notifications\Channels\ChannelRegistry;
use App\Support\Delivery\DeliveryAnswer;

/**
 * "Send a test": one message to the channel now, outside the queue, so the
 * user sees the answer. It counts as the channel's latest delivery.
 */
final readonly class TestChannel
{
    public function __construct(
        private ChannelRegistry $drivers,
        private ChannelMessages $messages,
    ) {}

    public function execute(NotificationChannel $channel): DeliveryAnswer
    {
        $answer = $this->drivers->get($channel->driver)->deliver($this->messages->test($channel->user), $channel->config);

        $channel->update(['last_failure' => $answer->failure()]);

        return $answer;
    }
}

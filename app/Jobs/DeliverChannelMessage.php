<?php

namespace App\Jobs;

use App\Models\NotificationChannel;
use App\Notifications\Channels\ChannelRegistry;
use App\Notifications\Channels\Data\ChannelMessage;
use App\Support\Delivery\DeliveryRetry;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\SerializesModels;

/**
 * One instant message to one channel, posted outside the run that wrote the
 * notification. Carries the channel, never its address — a credential has
 * no place in the queue. How the last try went stays on the channel, for the
 * settings page to tell.
 */
class DeliverChannelMessage implements ShouldQueue
{
    use Dispatchable, Queueable, SerializesModels;

    /** A channel removed before the queue got here has nobody to message. */
    public bool $deleteWhenMissingModels = true;

    public int $tries = DeliveryRetry::TRIES;

    public function __construct(private readonly NotificationChannel $channel, private readonly ChannelMessage $message) {}

    public function handle(ChannelRegistry $drivers): void
    {
        // Switched off while queued, or a kind the instance no longer offers.
        $driver = $drivers->find($this->channel->driver);

        if (! $this->channel->enabled || $driver === null) {
            return;
        }

        $answer = $driver->deliver($this->message, $this->channel);

        $retryAfter = DeliveryRetry::after($answer, $this->attempts());

        if ($retryAfter !== null) {
            $this->release($retryAfter);

            return;
        }

        $this->channel->update(['last_failure' => $answer->failure()]);
    }
}

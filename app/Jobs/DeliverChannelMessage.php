<?php

namespace App\Jobs;

use App\Models\NotificationChannel;
use App\Notifications\Channels\ChannelRegistry;
use App\Notifications\Channels\Data\ChannelMessage;
use App\Support\Delivery\DeliveryAnswer;
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

    public int $tries = 5;

    /** Seconds before the second and the third try, when the service was not there to answer. */
    public const RETRY_AFTER = [60, 300];

    private const UNAVAILABLE_TRIES = 3;

    /**
     * A throttled message comes back once the service's window (a few
     * seconds for a chat webhook) has passed, spread so a burst of the day's
     * releases does not return all at once and hit the limit again.
     */
    private const THROTTLED_DELAY_SECONDS = 5;

    private const THROTTLED_SPREAD_SECONDS = 10;

    public function __construct(private readonly NotificationChannel $channel, private readonly ChannelMessage $message) {}

    public function handle(ChannelRegistry $drivers): void
    {
        // Switched off while queued, or a kind the instance no longer offers.
        $driver = $drivers->find($this->channel->driver);

        if (! $this->channel->enabled || $driver === null) {
            return;
        }

        $answer = $driver->deliver($this->message, $this->channel->config);

        if ($answer === DeliveryAnswer::Throttled && $this->attempts() < $this->tries) {
            $this->release(self::THROTTLED_DELAY_SECONDS + random_int(0, self::THROTTLED_SPREAD_SECONDS));

            return;
        }

        if ($answer === DeliveryAnswer::Unavailable && $this->attempts() < self::UNAVAILABLE_TRIES) {
            $this->release(self::RETRY_AFTER[$this->attempts() - 1] ?? 0);

            return;
        }

        $this->channel->update(['last_failure' => $answer->failure()]);
    }
}

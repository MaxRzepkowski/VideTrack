<?php

namespace App\Jobs;

use App\Integrations\Discord\DiscordAnswer;
use App\Integrations\Discord\DiscordClient;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * One instant Discord ping, posted outside the run that wrote the
 * notification: a slow or dead webhook of one user never holds up the
 * daily releases of everyone else. Carries the user, never the URL — a
 * credential has no place in the queue.
 */
class DeliverDiscordMessage implements ShouldQueue
{
    use Dispatchable, Queueable, SerializesModels;

    /** An account removed before the queue got here has nobody to ping. */
    public bool $deleteWhenMissingModels = true;

    public int $tries = 5;

    /** Seconds before the second and the third try, when Discord was not there to answer. */
    private const RETRY_AFTER = [60, 300];

    private const UNAVAILABLE_TRIES = 3;

    /**
     * A throttled ping comes back once Discord's window (a couple of seconds
     * per webhook) has passed, spread so a burst of releases of the day does
     * not return all at once and hit the limit again.
     */
    private const THROTTLED_DELAY_SECONDS = 5;

    private const THROTTLED_SPREAD_SECONDS = 10;

    /**
     * @param  array<string, mixed>  $message  a Discord webhook payload
     */
    public function __construct(private readonly User $user, private readonly array $message) {}

    public function handle(DiscordClient $discord): void
    {
        $url = $this->user->discordWebhookUrl();

        if ($url === null) {
            return;
        }

        $answer = $discord->send($url, $this->message);

        if ($answer === DiscordAnswer::Throttled && $this->attempts() < $this->tries) {
            $this->release(self::THROTTLED_DELAY_SECONDS + random_int(0, self::THROTTLED_SPREAD_SECONDS));

            return;
        }

        if ($answer === DiscordAnswer::Unavailable && $this->attempts() < self::UNAVAILABLE_TRIES) {
            $this->release(self::RETRY_AFTER[$this->attempts() - 1] ?? 0);

            return;
        }

        if ($answer !== DiscordAnswer::Delivered) {
            Log::warning('Discord did not take an instant notification.', ['user' => $this->user->id, 'answer' => $answer->name]);
        }
    }
}

<?php

namespace App\Jobs;

use App\Accounts\Preferences\DiscordMode;
use App\Integrations\Discord\DiscordAnswer;
use App\Integrations\Discord\DiscordClient;
use App\Jobs\Concerns\RunsAsTask;
use App\Models\TaskRun;
use App\Models\User;
use App\Notifications\DiscordDigestComposer;
use App\Notifications\MediaEvent;
use App\Tasks\TaskName;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;

/**
 * The daily Discord digest: one webhook message per user summarizing the
 * notifications created since their previous digest. Runs hourly and fires
 * for each user once their LOCAL clock passes the fixed digest hour — the
 * `discord_digest_at` column claims the day's slot, so a catch-up re-run in
 * the same day never doubles a digest, and a run after downtime still
 * delivers (any hour past the slot counts, not just the exact one).
 */
class SendDiscordDigests implements ShouldQueue
{
    use Dispatchable, Queueable, RunsAsTask;

    /**
     * One HTTP round trip per user, and DiscordClient allows each 10 seconds
     * — the worker's 60-second default would let a handful of dead webhooks
     * kill the run before it reaches everyone else. Stays under the database
     * queue's retry_after (3690s) so a slow run is never picked up twice.
     */
    public int $timeout = 900;

    public int $maxExceptions = self::BREAKS_ALLOWED;

    /** Local hour a digest goes out at (fixed, per user timezone). */
    public const DIGEST_HOUR = 8;

    public function __construct(private readonly TaskRun $taskRun) {}

    /** Creates the pending task_runs record and queues the job. */
    public static function queueTask(): void
    {
        self::dispatch(TaskRun::queueTask(TaskName::DiscordDigest->value));
    }

    public function handle(DiscordDigestComposer $composer, DiscordClient $discord): void
    {
        $this->taskRun->markStarted();

        $sent = 0;
        $failed = 0;
        $waiting = 0;
        $done = 0;
        $empty = 0;

        // Only webhook users are candidates, and they stream in batches: the
        // hourly run must not pull every account into memory to skip almost
        // all of them. `discord_mode` stays a PHP check — its default lives in
        // UserPreferences, so an absent key still means "digest".
        $candidates = User::query()->whereNotNull('discord_webhook_url');

        foreach ($candidates->lazyById(200) as $user) {
            $url = $user->discordWebhookUrl();

            if ($url === null || $user->preferences->discordMode() !== DiscordMode::Digest) {
                continue;
            }

            $timezone = $user->preferences->effectiveTimezone();
            $local = now($timezone);

            if ($local->hour < self::DIGEST_HOUR) {
                $waiting++;

                continue;
            }

            $slot = $local->copy()->startOfDay()->addHours(self::DIGEST_HOUR);

            if ($user->discord_digest_at !== null && $user->discord_digest_at->greaterThanOrEqualTo($slot)) {
                $done++;

                continue;
            }

            $notifications = $user->notifications()
                ->where('type', MediaEvent::class)
                ->where('created_at', '>', $user->discord_digest_at ?? now()->subDay())
                // The relation reads newest first; a digest tells the day in order.
                ->reorder()
                ->oldest()
                ->orderBy('id')
                ->get();

            $claimedAt = now();

            if ($notifications->isEmpty()) {
                // Today's slot is claimed even with nothing to send — the
                // window then stays "since yesterday's digest", never unbounded.
                $user->forceFill(['discord_digest_at' => $claimedAt])->save();
                $empty++;

                continue;
            }

            $locale = $user->preferredLocale();
            $answers = [];

            foreach ($composer->messages($notifications, $locale, $timezone) as $content) {
                $answers[] = $answer = $discord->send($url, ['content' => $content]);

                if ($answer !== DiscordAnswer::Delivered) {
                    break;
                }
            }

            $last = end($answers);

            // Discord down or busy before anything went out: the slot stays
            // free and the next hourly run sends the same day. Once part of it
            // is out, or Discord refused it for good, the day is done — a
            // second attempt would repeat what the channel already shows.
            $retryable = in_array($last, [DiscordAnswer::Unavailable, DiscordAnswer::Throttled], true) && count($answers) === 1;

            if (! $retryable) {
                $user->forceFill(['discord_digest_at' => $claimedAt])->save();
            }

            $last === DiscordAnswer::Delivered ? $sent++ : $failed++;
        }

        $hour = sprintf('%02d:00', self::DIGEST_HOUR);

        $this->taskRun->markSuccess(
            "Sent {$sent} digests, {$failed} failed, {$empty} had nothing new, "
            ."{$done} already covered today, {$waiting} waiting for their local {$hour}."
        );
    }
}

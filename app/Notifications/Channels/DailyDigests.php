<?php

namespace App\Notifications\Channels;

use App\Models\NotificationChannel;
use App\Models\User;
use App\Notifications\Channels\Data\ChannelDigest;
use App\Notifications\MediaEvent;
use App\Support\Delivery\DeliveryAnswer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Carbon;

/**
 * The daily digest: one summary per digest channel of the notifications
 * since that channel's previous digest. A channel's turn comes once its
 * owner's LOCAL clock passes the fixed digest hour, and its `digest_sent_at`
 * claims the day's slot — a run repeated the same day never doubles a
 * digest, and a run after downtime still delivers (any hour past the slot
 * counts, not just the exact one).
 */
final readonly class DailyDigests
{
    /** Local hour a digest goes out at (fixed, in each user's timezone). */
    public const HOUR = 8;

    /**
     * How far back a digest reaches at most: past a night of downtime, but a
     * channel switched off for a month starts again without the month.
     */
    public const MAX_WINDOW_DAYS = 2;

    private const BATCH = 200;

    public function __construct(
        private ChannelMessages $messages,
        private ChannelRegistry $drivers,
    ) {}

    public function send(): DigestRun
    {
        $run = new DigestRun;

        $users = User::query()
            ->whereHas('notificationChannels', fn (Builder $channels) => $channels->delivering(ChannelMode::Digest))
            ->with(['notificationChannels' => fn (HasMany $channels) => $channels->delivering(ChannelMode::Digest)->orderBy('id')])
            ->lazyById(self::BATCH);

        foreach ($users as $user) {
            $this->sendFor($user, $user->notificationChannels, $run);
        }

        return $run;
    }

    /**
     * @param  Collection<int, NotificationChannel>  $channels  the user's digest channels
     */
    private function sendFor(User $user, Collection $channels, DigestRun $run): void
    {
        $local = now($user->preferences->effectiveTimezone());

        if ($local->hour < self::HOUR) {
            $run->waiting += $channels->count();

            return;
        }

        $slot = $local->copy()->startOfDay()->addHours(self::HOUR);
        $due = $channels->filter(fn (NotificationChannel $channel): bool => $channel->digest_sent_at === null || $channel->digest_sent_at->lessThan($slot));
        $run->done += $channels->count() - $due->count();

        if ($due->isEmpty()) {
            return;
        }

        // One read for every due channel of the user: from the earliest
        // window, each channel then takes what falls in its own.
        $notifications = $user->notifications()
            ->where('type', MediaEvent::class)
            ->where('created_at', '>', $due->map($this->windowStart(...))->min())
            // The relation reads newest first; a digest tells the day in order.
            ->reorder()
            ->oldest()
            ->orderBy('id')
            ->get();

        /** @var array<string, ChannelDigest|null> $digests by window start */
        $digests = [];

        foreach ($due as $channel) {
            $driver = $this->drivers->find($channel->driver);

            // A kind of channel the instance no longer offers.
            if ($driver === null) {
                continue;
            }

            $window = $this->windowStart($channel);
            $claimedAt = now();

            if (! array_key_exists($window->toIso8601String(), $digests)) {
                $digests[$window->toIso8601String()] = $this->messages->digest(
                    $notifications->filter(fn (DatabaseNotification $notification): bool => $notification->created_at?->greaterThan($window) === true)->values(),
                    $user,
                );
            }

            $digest = $digests[$window->toIso8601String()];

            if ($digest === null) {
                // Today's slot is claimed even with nothing to send, so the
                // next window starts from today's digest.
                $channel->update(['digest_sent_at' => $claimedAt]);
                $run->empty++;

                continue;
            }

            $answers = $driver->deliverDigest($digest, $channel->config);
            $last = end($answers);

            // The service down or busy before anything went out: the slot
            // stays free and the next hourly run sends the same day. Once part
            // of it is out, or the service refused it for good, the day is
            // done — a second attempt would repeat what the channel already shows.
            $retryable = $last->isTemporary() && count($answers) === 1;

            $channel->update([
                'last_failure' => $last->failure(),
                ...($retryable ? [] : ['digest_sent_at' => $claimedAt]),
            ]);

            $last === DeliveryAnswer::Delivered ? $run->sent++ : $run->failed++;
        }
    }

    /** Where a channel's digest starts: its last digest, at most MAX_WINDOW_DAYS back. */
    private function windowStart(NotificationChannel $channel): Carbon
    {
        $furthest = now()->subDays(self::MAX_WINDOW_DAYS);

        return $channel->digest_sent_at?->greaterThan($furthest) === true ? $channel->digest_sent_at : $furthest;
    }
}

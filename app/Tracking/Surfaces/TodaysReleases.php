<?php

namespace App\Tracking\Surfaces;

use App\Accounts\Preferences\NotificationEvent;
use App\Actions\Media\SendMediaNotification;
use App\Enums\ReleaseKind;
use App\Models\User;
use App\Notifications\MediaEventPayload;
use App\Tracking\Releases\ReleaseEntry;
use App\Tracking\Releases\ReleaseFeed;
use App\Tracking\Surface;

/**
 * Today's releases of one user's tracked titles as notifications — aired
 * episodes, season premieres, title premieres, films leaving cinemas — from
 * the notifications column of the same matrix the calendar reads, so a ping
 * never surprises: whatever it announces was already visible on the calendar.
 * Dedup by payload key keeps catch-up re-runs from doubling a day.
 */
final readonly class TodaysReleases
{
    public function __construct(
        private ReleaseFeed $feed,
        private SendMediaNotification $send,
    ) {}

    /** Whether the user wants any of today's release pings at all. */
    public function wantedBy(User $user): bool
    {
        $preferences = $user->preferences;

        return $preferences->notifies(NotificationEvent::Episodes)
            || $preferences->notifies(NotificationEvent::Premieres)
            || $preferences->releaseMode(Surface::Notifications)->showsDigital();
    }

    /**
     * Sends the user today's release pings; answers how many were new.
     */
    public function announce(User $user, string $today): int
    {
        $created = 0;

        $entries = $this->feed
            ->entries($user, Surface::Notifications, $today, $today)
            // Per-platform game variants collapse — the event is about the
            // title, not the release row. (A film premiering and reaching VOD
            // on the same day arrives merged already, as one `both` entry;
            // this still guards the rest.)
            ->unique(fn (ReleaseEntry $entry) => $entry->media->type.':'.$entry->media->ref);

        foreach ($entries as $entry) {
            $event = MediaEventPayload::released($entry, $today);

            // Home releases answer to the release mode alone, which the feed
            // already applied — a viewer who wants only those keeps premieres
            // switched off and still gets pinged, also when the film opens in
            // cinemas and at home on the same day.
            if (! $event->wantedBy($user)) {
                if ($entry->media->releaseKind !== ReleaseKind::Both) {
                    continue;
                }

                $event = MediaEventPayload::homeReleased($entry, $today);
            }

            $created += $this->send->execute($user, $event) ? 1 : 0;
        }

        return $created;
    }
}

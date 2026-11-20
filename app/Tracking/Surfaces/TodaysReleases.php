<?php

namespace App\Tracking\Surfaces;

use App\Accounts\Preferences\NotificationEvent;
use App\Actions\Media\SendMediaNotification;
use App\Enums\ReleaseKind;
use App\Models\User;
use App\Notifications\Events\EpisodesReleased;
use App\Notifications\Events\HomeRelease;
use App\Notifications\Events\Released;
use App\Notifications\MediaEventPayload;
use App\Notifications\MediaEventType;
use App\Tracking\Releases\ReleaseEntry;
use App\Tracking\Releases\ReleaseFeed;
use App\Tracking\Surface;
use Illuminate\Support\Collection;

/**
 * Today's releases of one user's tracked titles as notifications — aired
 * episodes, season premieres, title premieres, films leaving cinemas — from
 * the notifications column of the same matrix the calendar reads, so a ping
 * never surprises: whatever it announces was already visible on the calendar.
 * Dedup by payload key keeps catch-up re-runs from doubling a day, and a
 * show's episodes out on one day arrive as one notification listing them.
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

        // A show's episodes out together are one piece of news, said where
        // its first one comes.
        $episodesOf = $entries
            ->filter(fn (ReleaseEntry $entry): bool => $entry->isPart())
            ->groupBy(fn (ReleaseEntry $entry): int => $entry->rootId);

        $said = $episodesOf->isEmpty() ? [] : $this->episodesSaid($user, $today);

        foreach ($entries as $entry) {
            $event = $entry->isPart()
                ? $this->episodesEvent($episodesOf->pull($entry->rootId)?->reject(fn (ReleaseEntry $episode): bool => isset($said[$episode->media->key()])), $today)
                : $this->titleEvent($entry, $user, $today);

            if ($event !== null && $event->wantedBy($user)) {
                $created += $this->send->execute($user, $event) ? 1 : 0;
            }
        }

        return $created;
    }

    /**
     * A title's own release. Home releases answer to the release mode alone,
     * which the feed already applied: a viewer who wants only those keeps
     * premieres switched off and still gets pinged, also when the film opens
     * in cinemas and at home on the same day.
     */
    private function titleEvent(ReleaseEntry $entry, User $user, string $today): MediaEventPayload
    {
        $event = Released::of($entry, $today);

        return ! $event->wantedBy($user) && $entry->media->releaseKind === ReleaseKind::Both
            ? HomeRelease::of($entry, $today)
            : $event;
    }

    /**
     * The episodes the user already heard about today, alone or among their
     * show's, so a second run the same day says only what is new.
     *
     * @return array<string, true> card key => true
     */
    private function episodesSaid(User $user, string $today): array
    {
        $said = [];

        $notifications = $user->notifications()
            ->whereIn('data->event', [MediaEventType::EpisodeAired->value, MediaEventType::SeasonPremiere->value])
            ->where('data->date', $today)
            ->get();

        foreach ($notifications as $notification) {
            foreach (MediaEventPayload::fromStored($notification->data)->cardKeys() as $key) {
                $said[$key] = true;
            }
        }

        return $said;
    }

    /**
     * The event for a show's episodes out today and not yet said, or null
     * when there are none (left, or once its show was taken).
     *
     * @param  Collection<int, ReleaseEntry>|null  $episodes
     */
    private function episodesEvent(?Collection $episodes, string $today): ?MediaEventPayload
    {
        if ($episodes === null || $episodes->isEmpty()) {
            return null;
        }

        if ($episodes->count() === 1) {
            return Released::of($episodes->sole(), $today);
        }

        return EpisodesReleased::several(
            array_values($episodes
                ->sort(fn (ReleaseEntry $a, ReleaseEntry $b): int => [$a->media->seasonNumber, $a->media->number] <=> [$b->media->seasonNumber, $b->media->number])
                ->all()),
            $today,
        );
    }
}

<?php

namespace App\Notifications\Events;

use App\Enums\ReleaseKind;
use App\Notifications\MediaEventPayload;
use App\Notifications\MediaEventType;
use App\Tracking\Releases\ReleaseEntry;

/**
 * A release reaching the user today, as the kind of event it is: an aired
 * episode, a premiere, a film leaving cinemas. Not a kind itself: it picks one.
 */
final readonly class Released
{
    public static function of(ReleaseEntry $entry, string $today): MediaEventPayload
    {
        return match (true) {
            $entry->media->releaseKind === ReleaseKind::Home => HomeRelease::of($entry, $today),
            $entry->isPart() => EpisodesReleased::one($entry, $today),
            default => Premiere::of($entry, $today),
        };
    }

    /**
     * One release on a day as an event of its kind, keyed by the release and
     * the day so a catch-up re-run never says it twice. A home release keys
     * apart from a premiere of the same title on the same day.
     */
    public static function entry(MediaEventType $type, ReleaseEntry $entry, string $today): MediaEventPayload
    {
        $media = $entry->media;
        $prefix = $type === MediaEventType::HomeRelease ? 'home-release' : 'released';

        return new MediaEventPayload(
            type: $type,
            key: "{$prefix}:{$media->type}:{$media->ref}:{$today}",
            media: $media->jsonSerialize(),
            showTitle: $entry->showTitle,
            date: $today,
            season: $media->seasonNumber,
        );
    }
}

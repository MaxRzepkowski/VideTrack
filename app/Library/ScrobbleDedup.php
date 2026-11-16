<?php

namespace App\Library;

use App\Enums\PlayOutcome;
use App\Models\MediaItem;
use App\Models\Play;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * Whether a viewing a media server reported is one the library already
 * holds: two servers watching the same player (Jellyfin and Tautulli), or a
 * webhook and a later import of the same history, report it at slightly
 * different times. Per user, not per source — which is the whole point.
 */
final readonly class ScrobbleDedup
{
    /** How far apart, either way, two reports of one viewing may land. */
    public static function windowHours(): int
    {
        return config()->integer('tracker.scrobble.dedup_window_hours');
    }

    public function alreadyPlayed(User $user, MediaItem $item, CarbonImmutable $at): bool
    {
        $hours = self::windowHours();

        return Play::query()
            ->where('user_id', $user->id)
            ->where('media_item_id', $item->id)
            ->where('outcome', PlayOutcome::Completed->value)
            ->whereBetween('watched_at', [$at->subHours($hours), $at->addHours($hours)])
            ->exists();
    }
}

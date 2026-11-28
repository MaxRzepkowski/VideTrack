<?php

namespace App\Library;

use App\Enums\PlayOutcome;
use App\Models\MediaItem;
use App\Models\Play;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Whether a viewing reported from outside is one the library already holds:
 * two servers watching the same player, a webhook and a later import of the
 * same history, two trackers one app reports to — each lands at a slightly
 * different time. Per user, not per source: that is the whole point.
 *
 * A held entry is taken at its stamp, whatever dated it: a play placed on a
 * release date or on a source's last activity can stand for a viewing near
 * that moment, which is rare and costs one entry, never a duplicate.
 */
final readonly class ScrobbleDedup
{
    public function __construct(private MutationContext $mutations) {}

    /** How far apart, either way, two reports of one viewing may land. */
    public static function windowHours(): int
    {
        return config()->integer('tracker.scrobble.dedup_window_hours');
    }

    /**
     * A media server's report against every finished viewing in the window,
     * its own too: one server tells one viewing more than once (a stop, then
     * "watched").
     */
    public function alreadyPlayed(User $user, MediaItem $item, CarbonImmutable $at): bool
    {
        return $this->finishedAround($user, $item, $at)->exists();
    }

    /**
     * Whether an imported viewing is one the library already holds, weighed
     * as the kind of record it comes from says ({@see RecordKind}). Only a
     * finished viewing stands for another.
     */
    public function heardBefore(User $user, MediaItem $item, ?CarbonImmutable $at, PlayDating $dating, PlayOutcome $outcome, RecordKind $record): bool
    {
        if ($outcome !== PlayOutcome::Completed) {
            return false;
        }

        return match ($record) {
            RecordKind::History => $this->reportedElsewhere($user, $item, $at, $dating),
            RecordKind::PlaybackLog => $at !== null && $this->alreadyPlayed($user, $item, $at),
            RecordKind::Backup => false,
        };
    }

    /**
     * An imported viewing against the finished viewings any other source
     * holds in the window, a click or a history whose source was never kept
     * included. The source's own entries hours apart are two viewings: its
     * history lists each one, and the same entry again is the same moment.
     * Only a viewing dated to when it happened is weighed; a date that is the
     * source's last activity, or none, says nothing about the moment. The
     * source is the import under way.
     */
    public function reportedElsewhere(User $user, MediaItem $item, ?CarbonImmutable $at, PlayDating $dating): bool
    {
        if ($at === null || $dating !== PlayDating::Viewing) {
            return false;
        }

        return $this->finishedAround($user, $item, $at)
            ->where(fn (Builder $plays) => $plays->whereNull('source')->orWhere('source', '!=', $this->mutations->current()->value()))
            // The very moment is the same entry again, whoever wrote it: the
            // import's own write knows it, and fills in what it lacked.
            ->whereNotIn('watched_at', BackfillRules::stampsOf($at))
            ->exists();
    }

    /** @return Builder<Play> */
    private function finishedAround(User $user, MediaItem $item, CarbonImmutable $at): Builder
    {
        $hours = self::windowHours();

        return Play::query()
            ->where('user_id', $user->id)
            ->where('media_item_id', $item->id)
            ->where('outcome', PlayOutcome::Completed->value)
            ->whereBetween('watched_at', [$at->subHours($hours), $at->addHours($hours)]);
    }
}

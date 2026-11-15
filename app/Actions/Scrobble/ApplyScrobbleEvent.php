<?php

namespace App\Actions\Scrobble;

use App\Actions\Library\LogProgress;
use App\Actions\Library\MarkWatched;
use App\Actions\Library\RateItem;
use App\Catalog\ItemMetadata;
use App\Catalog\MediaTypeRegistry;
use App\Integrations\Scrobble\Data\ScrobbleEvent;
use App\Integrations\Scrobble\Data\ScrobbleOutcome;
use App\Integrations\Scrobble\DeliveryNote;
use App\Integrations\Scrobble\ScrobbleEventKind;
use App\Library\RatingScale;
use App\Library\ScrobbleDedup;
use App\Library\WatchedAt;
use App\Models\MediaItem;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * What a playback event means for one title, written through the ordinary
 * library actions: a finish is a play, a partial stop on a type that counts
 * its progress is a session, a rating is a rating. A media server reports a
 * stop, not a verdict, so "watched" starts at the completion threshold.
 */
final readonly class ApplyScrobbleEvent
{
    public function __construct(
        private MediaTypeRegistry $types,
        private MarkWatched $mark,
        private LogProgress $logProgress,
        private RateItem $rate,
        private ScrobbleDedup $dedup,
    ) {}

    public function execute(User $user, MediaItem $item, ScrobbleEvent $event): ScrobbleOutcome
    {
        $occurredAt = $event->occurredAt ?? CarbonImmutable::now();

        if ($event->kind === ScrobbleEventKind::Rate) {
            // A source that reports ratings it does not want carried over
            // sends the event without one, so the log still shows the server
            // talking to us.
            if ($event->rating === null) {
                return ScrobbleOutcome::ignored(DeliveryNote::RatingNotImported);
            }

            if (! RatingScale::holds($event->rating)) {
                return ScrobbleOutcome::ignored(DeliveryNote::RatingOutOfRange);
            }

            $this->rate->execute($user, $item, $event->rating);

            return ScrobbleOutcome::accepted();
        }

        if ($this->completes($event)) {
            if ($this->dedup->alreadyPlayed($user, $item, $occurredAt)) {
                return ScrobbleOutcome::ignored(DeliveryNote::AlreadyPlayed);
            }

            $this->mark->execute($user, $item, WatchedAt::at($occurredAt));

            return ScrobbleOutcome::accepted();
        }

        // Below the threshold, but on a type that counts its own progress
        // (pages, minutes, chapters, issues), a partial stop is real
        // information — and a progress report is nothing else.
        if (in_array($event->kind, [ScrobbleEventKind::Stop, ScrobbleEventKind::Pause, ScrobbleEventKind::Progress], true)) {
            $position = $this->positionFrom($item, $event);

            if ($position !== null) {
                $this->logProgress->execute($user, $item, null, $position);

                return ScrobbleOutcome::accepted();
            }

            // A film has no page to be on: the report was fine, the type
            // just has nowhere to keep it.
            if ($event->kind === ScrobbleEventKind::Progress && $this->types->get($item->type)->progressUnit === null) {
                return ScrobbleOutcome::ignored(DeliveryNote::NoProgressUnit);
            }

            return ScrobbleOutcome::ignored(DeliveryNote::BelowThreshold);
        }

        // Start / Added mean nothing to the framework. A source that wants
        // them to mean something says so in its own parse().
        return ScrobbleOutcome::ignored(DeliveryNote::KindNotActionable);
    }

    private function completes(ScrobbleEvent $event): bool
    {
        if ($event->kind === ScrobbleEventKind::Complete) {
            return true;
        }

        return $event->kind === ScrobbleEventKind::Stop
            && $event->progressPercent !== null
            && $event->progressPercent >= config()->integer('tracker.scrobble.completion_threshold', 85);
    }

    /**
     * Where the user is, in the type's own unit: the sender's count when it
     * gave one, else what its percentage means on a type with a maximum.
     * Null on a type without a unit, and when there is nothing to go on.
     */
    private function positionFrom(MediaItem $item, ScrobbleEvent $event): ?int
    {
        $unit = $this->types->get($item->type)->progressUnit;

        if ($unit === null) {
            return null;
        }

        if ($event->position !== null) {
            return $event->position;
        }

        if ($event->progressPercent === null) {
            return null;
        }

        $total = ItemMetadata::fromStored($item->metadata)->progressTotal($unit);

        return $total === null ? null : (int) round($total * $event->progressPercent / 100);
    }
}

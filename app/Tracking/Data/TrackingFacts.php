<?php

namespace App\Tracking\Data;

use App\Tracking\TrackingFactsQuery;
use App\Tracking\TrackingSource;

/**
 * What one user has done with one title (a hierarchy root) that the tracking
 * matrix cares about. Built in bulk by {@see TrackingFactsQuery}.
 */
final readonly class TrackingFacts
{
    public function __construct(
        public bool $muted = false,
        /** An open attempt, paused. */
        public bool $onHold = false,
        /** A dropped entry on the title itself — a skipped season does not count. */
        public bool $dropped = false,
        /** The title or any part of it is on the backlog. */
        public bool $backlog = false,
        /** Anything logged on the title or a part of it: a play, a status, a rating, a favorite. */
        public bool $activity = false,
        /** A bell the user rang on the title. */
        public bool $manualFollow = false,
        /** A bell a followed person, company or collection rang for the user. */
        public bool $creatorFollow = false,
    ) {}

    /**
     * The row the title falls in, in the order the bell reads it: a status
     * outranks the backlog, the backlog outranks history, and a bell only
     * speaks for a title nothing else covers. Null when muted or untouched.
     */
    public function source(): ?TrackingSource
    {
        return match (true) {
            $this->muted => null,
            $this->onHold => TrackingSource::OnHold,
            $this->dropped => TrackingSource::Dropped,
            $this->backlog => TrackingSource::Backlog,
            $this->activity => TrackingSource::Activity,
            $this->manualFollow => TrackingSource::ManualFollow,
            $this->creatorFollow => TrackingSource::CreatorFollow,
            default => null,
        };
    }
}

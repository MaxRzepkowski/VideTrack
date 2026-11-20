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
        /**
         * The title's kind, which may have sources of its own. Null on facts
         * read for a card, which say only why a title is tracked: no surface
         * can be asked about them.
         */
        public ?string $type = null,
    ) {}

    /**
     * Whether the user took a stance on the title themselves: a bell a
     * followed person, company or collection rang is not one.
     */
    public function isChosenByUser(): bool
    {
        return $this->muted || ! in_array($this->source(), [null, TrackingSource::CreatorFollow], true);
    }

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

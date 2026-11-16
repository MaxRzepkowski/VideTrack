<?php

namespace App\Integrations\Export;

use App\Enums\PositionStatus;
use App\Models\LibraryEntry;
use App\Models\ListItem;
use App\Models\MediaItem;
use App\Models\MediaList;
use App\Models\MediaUserState;
use App\Models\Play;
use App\Models\Rating;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;

/**
 * Everything the library keeps of one title for one user, as an export to
 * a list-keeping service reads it: the projection's entry (absent for a
 * title with no history), the rating, the open attempt, the plays oldest
 * first and the lists the title is on, each membership with its list.
 */
final readonly class TitleStanding
{
    /**
     * @param  Collection<int, Play>  $plays
     * @param  Collection<int, ListItem>  $memberships
     */
    public function __construct(
        public MediaItem $item,
        public ?LibraryEntry $entry,
        public ?Rating $rating,
        public ?MediaUserState $opened,
        public Collection $plays,
        public Collection $memberships,
    ) {}

    /**
     * The one state the title stands in on a service's list, the open
     * attempt first: a rewatch or re-read under way is under way again, as
     * the services show it. A finished viewing outweighs a later drop, and a
     * rating alone is a title seen. A title on no list but the user's own
     * has no state there; each service says what that means to it.
     */
    public function listStatus(): ?ListStatus
    {
        $status = $this->entry?->status;

        return match (true) {
            $status === PositionStatus::InProgress => ListStatus::Current,
            $status === PositionStatus::OnHold => ListStatus::OnHold,
            ($this->entry->plays ?? 0) > 0 => ListStatus::Completed,
            $status === PositionStatus::Dropped => ListStatus::Dropped,
            $this->onBacklog() => ListStatus::Planned,
            $this->rating !== null => ListStatus::Completed,
            default => null,
        };
    }

    public function onBacklog(): bool
    {
        return $this->memberships->contains(fn (ListItem $membership): bool => $membership->list?->system_key === MediaList::SYSTEM_WATCHLIST);
    }

    /** The first the library saw of the title being taken up: its oldest play, or the day an attempt was opened. */
    public function startedAt(): ?CarbonImmutable
    {
        $moments = array_filter([$this->plays->first()?->watched_at, $this->opened?->created_at]);

        return $moments === [] ? null : CarbonImmutable::instance(min($moments));
    }
}

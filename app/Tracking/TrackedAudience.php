<?php

namespace App\Tracking;

use App\Models\MediaItem;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Who hears about a change to a title (a moved date, a new status, a new
 * season) — the notifications column of the matrix read from the title's
 * side, so the users pinged are exactly those whose daily release pings
 * carry the title.
 */
final readonly class TrackedAudience
{
    public function __construct(private TrackingFactsQuery $facts) {}

    /**
     * @return Collection<int, User>
     */
    public function usersFor(MediaItem $root): Collection
    {
        $facts = $this->facts->forRoot($root);

        if ($facts === []) {
            return new Collection;
        }

        return User::query()
            ->whereIn('id', array_keys($facts))
            ->get()
            ->filter(fn (User $user): bool => Surface::Notifications->carries($user->preferences, $facts[$user->id]))
            ->values();
    }
}

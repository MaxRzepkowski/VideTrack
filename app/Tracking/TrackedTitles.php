<?php

namespace App\Tracking;

use App\Models\User;

/**
 * The titles a surface carries for one user — the matrix read from the user's
 * side. {@see TrackedAudience} reads the same matrix from a title's side.
 */
final readonly class TrackedTitles
{
    public function __construct(private TrackingFactsQuery $facts) {}

    /**
     * @return array<int, TrackingSource> root id => the row that carries it
     */
    public function of(User $user, Surface $surface): array
    {
        $tracked = [];
        // Read once: every read of a model's cast object writes it back to the attributes first.
        $preferences = $user->preferences;

        foreach ($this->facts->forUser($user) as $rootId => $facts) {
            $source = $facts->source();

            if ($source !== null && $surface->carries($preferences, $facts)) {
                $tracked[$rootId] = $source;
            }
        }

        return $tracked;
    }
}

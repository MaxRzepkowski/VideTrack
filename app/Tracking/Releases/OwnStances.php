<?php

namespace App\Tracking\Releases;

use App\Models\MediaItem;
use App\Models\User;
use App\Tracking\TrackingFactsQuery;
use Illuminate\Database\Eloquent\Collection;

/**
 * The releases of their own of a tracked game (its DLC, a remake) that a user
 * keeps as titles of their own — tracked, muted, dropped, on hold: those
 * follow that stance, on every surface, instead of the game's. A bell a
 * followed creator rang on one is not the user's stance.
 */
final readonly class OwnStances
{
    public function __construct(private TrackingFactsQuery $facts) {}

    /**
     * @param  list<string>  $refs  the releases' refs at the game's provider
     * @return array<string, true> those of the refs the user answers for themselves
     */
    public function of(User $user, string $provider, string $type, array $refs): array
    {
        $roots = $this->roots($provider, $type, $refs)->keyBy(fn (MediaItem $root): int => $root->id);
        $own = [];

        foreach ($this->facts->forUserTitles($user, array_values($roots->keys()->all())) as $rootId => $facts) {
            $root = $roots->get($rootId);

            if ($root !== null && $facts->isChosenByUser()) {
                $own[$root->provider_ref] = true;
            }
        }

        return $own;
    }

    /**
     * The same for everyone at once: news of a game's DLC reaches all its
     * users together.
     *
     * @param  list<string>  $refs
     * @return array<int, array<string, true>> user id => those of the refs they answer for themselves
     */
    public function ofEveryone(string $provider, string $type, array $refs): array
    {
        $own = [];

        $roots = $this->roots($provider, $type, $refs)->keyBy(fn (MediaItem $root): int => $root->id);

        foreach ($this->facts->forRoots(array_values($roots->keys()->all())) as $rootId => $byUser) {
            $root = $roots->get($rootId);

            foreach ($byUser as $userId => $facts) {
                if ($root !== null && $facts->isChosenByUser()) {
                    $own[$userId][$root->provider_ref] = true;
                }
            }
        }

        return $own;
    }

    /**
     * The stored titles the releases are.
     *
     * @param  list<string>  $refs
     * @return Collection<int, MediaItem>
     */
    private function roots(string $provider, string $type, array $refs): Collection
    {
        if ($refs === []) {
            return new Collection;
        }

        return MediaItem::query()
            ->whereNull('parent_id')
            ->where('provider', $provider)
            ->where('type', $type)
            ->whereIn('provider_ref', $refs)
            ->get(['id', 'type', 'provider_ref']);
    }
}

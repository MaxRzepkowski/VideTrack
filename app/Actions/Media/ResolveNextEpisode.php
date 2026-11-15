<?php

namespace App\Actions\Media;

use App\Catalog\TitleStore;
use App\Library\LibraryStates;
use App\Models\MediaItem;
use App\Models\User;

/**
 * Where a container stands for the user who asked: its first aired episode
 * when nothing is watched yet, the first gap once it is under way. The client
 * knows the show it clicked, never which leaf that is — specials seasons, a
 * first season numbered 0, an episode that has not aired yet are all the
 * hierarchy's business, so the answer is resolved here.
 *
 * It is the same `nextEpisode` every shelf reads, so "the next one" means one
 * thing across the app and no second definition is introduced here.
 */
final readonly class ResolveNextEpisode
{
    public function __construct(
        private LibraryStates $states,
        private TitleStore $titles,
    ) {}

    public function execute(User $user, MediaItem $container): ?MediaItem
    {
        $next = $this->states->resolveItem($user, $container)->nextEpisode;

        if ($next === null) {
            return null;
        }

        // The state was read off the stored tree, so its episode is stored.
        return $this->titles->stored($next->type, $next->ref);
    }
}

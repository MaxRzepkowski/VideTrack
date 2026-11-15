<?php

namespace App\Actions\Library;

use App\Catalog\Hierarchy;
use App\Catalog\MediaTypeRegistry;
use App\Library\LibraryProjector;
use App\Library\LoggedSessions;
use App\Models\LibraryEntry;
use App\Models\MediaItem;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Clears the status a title shows — the open state and a current drop alike.
 * One rule decides how far that reaches: the click removes what the user put
 * on THIS title, and nothing else.
 *
 * A season showing a status of its own therefore leaves the show alone, however
 * well the two match. Dropping a show is a dated history entry, not a flag —
 * clearing it from a season would delete a play row on a title the user never
 * touched, and that date cannot be recovered. A started season then falls back
 * to reading the show's status, which the card words differently ("dropped with
 * the show") and dates differently, so the click is visibly not a no-op; a
 * second clear from the same card is what reaches the show.
 *
 * A season WEARING the show's status is the one case that climbs: the status
 * on screen is the show's, there is nothing here to clear, and a click that
 * removed nothing would be a dead one. The modal says so before it happens.
 * "Wearing" is the projection's call (`status_inherited`), not a rule of its own
 * here — a finished, never-started or specials season shows nothing, and a
 * clear sent for one (only the API can) must not quietly resume the show.
 *
 * Going the other way changes nothing either: clearing a show leaves a
 * separately skipped season skipped, because skipping THAT season was its own
 * statement.
 */
final readonly class ResumeAttempt
{
    public function __construct(
        private MediaTypeRegistry $types,
        private Hierarchy $hierarchy,
        private SetMediaStatus $status,
        private DiscardAttempt $discard,
        private UndoDrop $undoDrop,
        private LibraryProjector $projector,
    ) {}

    public function execute(User $user, MediaItem $item, LoggedSessions $sessions = LoggedSessions::Keep): void
    {
        // Up to four writes on two positions, in one transaction (a season
        // resumed while its show stays dropped would contradict itself) and
        // one projection of the title after them all, not one per write.
        $this->projector->deferring($user, fn () => DB::transaction(function () use ($user, $item, $sessions): void {
            $root = $this->rootOf($item);

            // Read before writing: clearing the season changes what it shows, and
            // the decision below is about what it showed when the user clicked.
            $inherited = $root !== null && LibraryEntry::query()
                ->where('user_id', $user->id)
                ->where('media_item_id', $item->id)
                ->where('status_inherited', true)
                ->exists();

            match ($sessions) {
                LoggedSessions::Discard => $this->discard->execute($user, $item),
                LoggedSessions::Keep => $this->status->execute($user, $item, null),
            };

            $this->undoDrop->execute($user, $item);

            if (! $inherited) {
                return;
            }

            // The show is resumed, never discarded: a container logs no sessions
            // of its own, and what the user threw away was the season's attempt.
            $this->status->execute($user, $root, null);
            $this->undoDrop->execute($user, $root);
        }));
    }

    /**
     * The hierarchy root of a container that has one — null for a root itself
     * and for anything playable, neither of which inherits a status.
     */
    private function rootOf(MediaItem $item): ?MediaItem
    {
        $definition = $this->types->get($item->type);

        if ($definition->playable || $definition->isRoot()) {
            return null;
        }

        $root = $this->hierarchy->rootOf($item);

        return $root->is($item) ? null : $root;
    }
}

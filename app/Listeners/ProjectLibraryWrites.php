<?php

namespace App\Listeners;

use App\Events\Media\TitlePersisted;
use App\Events\Media\TitleRefreshed;
use App\Library\Events\MediaStatusChanged;
use App\Library\Events\PlayAmountChanged;
use App\Library\Events\PlayRecorded;
use App\Library\Events\PlayRemoved;
use App\Library\Events\ProgressLogged;
use App\Library\Events\RewatchCancelled;
use App\Library\Events\RewatchStarted;
use App\Library\LibraryProjector;
use App\Models\LibraryEntry;
use App\Models\User;

/**
 * Every change to a user's history, and every change to a title's tree,
 * moves the projection — synchronously, right after the write commits, so
 * the answer to the write already reads the new state. A wiped library
 * takes its projection along in its own transaction.
 */
final readonly class ProjectLibraryWrites
{
    public function __construct(private LibraryProjector $projector) {}

    public function handle(
        PlayRecorded|PlayRemoved|PlayAmountChanged|RewatchStarted|RewatchCancelled|ProgressLogged|MediaStatusChanged|TitlePersisted|TitleRefreshed $event,
    ): void {
        if ($event instanceof TitlePersisted || $event instanceof TitleRefreshed) {
            $this->projectTitle($event->itemId);

            return;
        }

        $user = User::query()->find($event->userId);

        // The account was deleted before its write was heard; its entries went with it.
        if ($user === null) {
            return;
        }

        match (true) {
            $event instanceof ProgressLogged, $event instanceof MediaStatusChanged => $this->projector->projectItems($user, [$event->itemId]),
            default => $this->projector->projectRoots($user, [$event->rootId]),
        };
    }

    /** The title's tree changed: everyone with history on it sees it anew. */
    private function projectTitle(int $rootId): void
    {
        $userIds = LibraryEntry::query()
            ->where('media_item_id', $rootId)
            ->pluck('user_id');

        foreach (User::query()->whereIn('id', $userIds)->get() as $user) {
            $this->projector->projectRoots($user, [$rootId]);
        }
    }
}

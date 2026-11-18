<?php

namespace App\Listeners;

use App\Events\Media\TitleRefreshed;
use App\Library\Events\LibraryCleared;
use App\Library\Events\LibraryProjected;
use App\Library\Events\MediaStatusChanged;
use App\Library\Events\PlayAmountChanged;
use App\Library\Events\PlayRecorded;
use App\Library\Events\PlayRemoved;
use App\Library\Events\ProgressLogged;
use App\Library\Events\RatingChanged;
use App\Library\Events\RatingRemoved;
use App\Library\Events\RewatchCancelled;
use App\Library\Events\RewatchStarted;
use App\Models\LibraryEntry;
use App\Support\Numbers;
use App\Users\Statistics\StatisticsCache;

/**
 * Whatever the statistics count changed, so what they kept of it no longer
 * holds: a user's history, ratings, statuses or projection, or a title they
 * have history on (its genres, credits and everyone else's ratings come with
 * a refresh). Lists, favourites and follows are not counted, so they keep it.
 *
 * A history write is heard as well as the projection it leads to: the
 * projection of a batch is written when the batch ends, and an entry that
 * changes no projected state (one more viewing) still changes the counts.
 */
final readonly class ForgetStatistics
{
    public function __construct(private StatisticsCache $statistics) {}

    public function handle(
        PlayRecorded|PlayRemoved|PlayAmountChanged|ProgressLogged|MediaStatusChanged|RatingChanged|RatingRemoved|RewatchStarted|RewatchCancelled|LibraryCleared|LibraryProjected|TitleRefreshed $event,
    ): void {
        if ($event instanceof TitleRefreshed) {
            $userIds = LibraryEntry::query()->where('media_item_id', $event->itemId)->distinct()->pluck('user_id');

            foreach (Numbers::ints($userIds) as $userId) {
                $this->statistics->forgetLibrary($userId);
            }

            return;
        }

        $this->statistics->forgetLibrary($event->userId);
    }
}

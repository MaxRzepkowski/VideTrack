<?php

namespace App\Actions\Library;

use App\Catalog\Hierarchy;
use App\Catalog\MediaTypeRegistry;
use App\Enums\PlayOutcome;
use App\Library\Attempts;
use App\Library\BackfillRules;
use App\Library\Events\PlayAmountChanged;
use App\Library\Events\PlayRecorded;
use App\Library\MutationContext;
use App\Library\MutationSource;
use App\Library\PlayDating;
use App\Library\WatchedAt;
use App\Models\MediaItem;
use App\Models\MediaUserState;
use App\Models\Play;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

/**
 * A viewing (or an abandoned attempt) from the user's history elsewhere — an
 * import, a restored backup. It is added as a dated fact, never as a click:
 *
 * - the same entry again (item, moment, run) is not added twice, so a file
 *   imports any number of times; a counter the first import lacked fills in;
 *   an entry without a date stands on the release date, and on a title
 *   never released — no day to tell entries apart — once per run, as does an
 *   entry dated only by the source's last activity ({@see PlayDating});
 * - it lands in the run under way on its date, or in the run its source
 *   numbers explicitly (a backup: 1 is the first run, whatever began after
 *   it; a later run is made — and announced — by its first viewing when the
 *   file did not bring the run itself);
 * - it settles only what the library learned of before the viewing
 *   happened: an earlier pause closes, an earlier drop of the show (or of a
 *   season, once the season is fully watched, as a click lifts it) lifts,
 *   its logged sessions frozen into the viewing as a click freezes them;
 *   anything newer stands;
 * - unlike a click it never reopens a dropped attempt of the item itself:
 *   the history keeps both entries ({@see MarkWatched} completes in place).
 */
final readonly class BackfillPlay
{
    public function __construct(
        private MediaTypeRegistry $types,
        private Hierarchy $hierarchy,
        private Attempts $attempts,
        private BackfillRules $rules,
        private BackfillCycle $cycles,
        private MutationContext $mutations,
    ) {}

    /**
     * @param  CarbonImmutable|null  $at  null when the source gives no date
     * @param  int|null  $cycle  the run's number as the source gives it; null places the entry by its date
     * @param  PlayDating  $dating  whether $at tells this entry from another
     * @param  MutationSource|null  $source  where the entry first came in, when the history being restored still knows (a backup); null stamps the traffic writing it now
     * @return bool whether a new entry was written
     */
    public function execute(
        User $user,
        MediaItem $item,
        ?CarbonImmutable $at,
        PlayOutcome $outcome = PlayOutcome::Completed,
        ?int $amount = null,
        ?int $cycle = null,
        PlayDating $dating = PlayDating::Viewing,
        ?MutationSource $source = null,
    ): bool {
        $undated = ($at === null && $item->release_date === null) || $dating === PlayDating::Activity;
        $at ??= WatchedAt::releaseDateOrNow()->of($item);
        $chain = $this->hierarchy->ancestry($item);
        $root = $chain[count($chain) - 1];
        $cycleId = match (true) {
            $cycle === null => $this->rules->cycleAt($user, $root, $at),
            $cycle >= 2 => $this->numberedCycle($user, $root, $cycle, $at),
            // The first run is the implicit one, whatever began after it.
            default => null,
        };
        $kept = $this->rules->amount($item, $amount);

        $containers = array_values(array_filter($chain, fn (MediaItem $node): bool => ! $this->types->get($node->type)->playable));

        $existing = Play::query()
            ->where('user_id', $user->id)
            ->where('media_item_id', $item->id)
            ->when(
                $undated,
                fn (Builder $plays) => $plays->where('outcome', $outcome->value),
                fn (Builder $plays) => $plays->where('watched_at', $at),
            )
            ->inCycle($cycleId)
            ->first();

        if ($existing !== null) {
            // Filling a missing counter is not overwriting: a re-import fills a
            // counter an earlier import lacked.
            if ($existing->amount === null && $kept !== null) {
                $existing->update(['amount' => $kept]);
                Event::dispatch(new PlayAmountChanged(userId: $user->id, source: $this->mutations->current(), itemId: $item->id, rootId: $root->id, playId: $existing->id, amount: $kept, previous: null));
            }

            return false;
        }

        $row = [
            'user_id' => $user->id,
            'media_item_id' => $item->id,
            'watch_cycle_id' => $cycleId,
            'watched_at' => $at,
            'outcome' => $outcome,
            'source' => ($source ?? $this->mutations->current())->value(),
            'amount' => $kept,
        ];

        $play = $outcome === PlayOutcome::Completed
            ? $this->settlingOlder($user, $item, $root, $containers, $cycleId, $at, $row)
            : Play::query()->create($row);

        Event::dispatch(PlayRecorded::of($user->id, $this->mutations->current(), $item, $root, [$play]));

        return true;
    }

    /**
     * The viewing written with what a click on that day would have settled,
     * as far as the library learned of it before the viewing happened: an
     * open state last changed earlier closes, a drop of the show (or of a
     * season the viewing completes) recorded earlier lifts. A drop or a pause
     * the library learned of later is the newer fact and stands — so a backup
     * restores as it was taken, and one file telling both a viewing and the
     * status the title is in now keeps the status. A pause is dated by its
     * last change, a drop by when it was written: a drop given the release
     * date as its day was still clicked after the viewings.
     *
     * @param  list<MediaItem>  $containers  the containers above the item, up to its root
     * @param  array<string, mixed>  $row
     */
    private function settlingOlder(
        User $user,
        MediaItem $item,
        MediaItem $root,
        array $containers,
        ?int $cycleId,
        CarbonImmutable $at,
        array $row,
    ): Play {
        $states = MediaUserState::query()
            ->where('user_id', $user->id)
            ->whereIn('media_item_id', array_unique([$item->id, $root->id]))
            ->where('updated_at', '<', $at)
            ->get(['id', 'media_item_id', 'progress']);

        $row['amount'] ??= $this->rules->amount($item, $states->firstWhere('media_item_id', $item->id)?->progress);

        $drops = $containers === [] ? collect() : Play::query()
            ->where('user_id', $user->id)
            ->whereIn('media_item_id', array_map(fn (MediaItem $node): int => $node->id, $containers))
            ->where('outcome', PlayOutcome::Dropped->value)
            ->where('created_at', '<', $at)
            ->inCycle($cycleId)
            ->get(['id', 'media_item_id']);

        if ($states->isEmpty() && $drops->isEmpty()) {
            return Play::query()->create($row);
        }

        return DB::transaction(function () use ($user, $root, $containers, $cycleId, $row, $states, $drops): Play {
            $play = Play::query()->create($row);

            MediaUserState::query()->whereKey($states->modelKeys())->delete();

            foreach ($containers as $container) {
                $ids = $drops->where('media_item_id', $container->id)->pluck('id');

                if ($ids->isNotEmpty() && ($container->is($root) || $this->attempts->fullyWatched($user, $container, $cycleId))) {
                    Play::query()->whereKey($ids)->delete();
                }
            }

            return $play;
        });
    }

    /**
     * A later run the source numbers, made by its first viewing when the file
     * did not bring the run itself — announced as a run the file did bring.
     */
    private function numberedCycle(User $user, MediaItem $root, int $number, CarbonImmutable $startedAt): ?int
    {
        return $this->cycles->execute($user, $root, $number, $startedAt)?->id;
    }
}

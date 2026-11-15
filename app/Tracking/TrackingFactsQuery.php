<?php

namespace App\Tracking;

use App\Catalog\Hierarchy;
use App\Enums\FollowMode;
use App\Enums\MediaUserStatus;
use App\Enums\PlayOutcome;
use App\Models\MediaItem;
use App\Models\MediaList;
use App\Models\User;
use App\Support\QueryRow;
use App\Tracking\Data\TrackingFacts;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The tracking facts of many titles at once, in either direction: every title
 * one user has left a trace on, or every user who left one on one title. Both
 * read the same traces with the same rules, so a title the calendar carries is
 * a title whose changes are announced — they differ only in what they narrow
 * by. Every trace comes back in one query; climbing to the titles adds one per
 * level of the hierarchy.
 */
final readonly class TrackingFactsQuery
{
    public function __construct(private Hierarchy $hierarchy) {}

    /**
     * @return array<int, TrackingFacts> root id => facts, for every title the user touched
     */
    public function forUser(User $user): array
    {
        return $this->byRoot($this->traces(userId: $user->id));
    }

    /**
     * The same, for just the given titles — what the cards on a page need.
     *
     * @param  list<int>  $rootIds
     * @return array<int, TrackingFacts> root id => facts, for those of the titles the user touched
     */
    public function forUserTitles(User $user, array $rootIds): array
    {
        if ($rootIds === []) {
            return [];
        }

        $traces = $this->traces(userId: $user->id, items: $this->hierarchy->treeIdsQuery($rootIds));

        return array_intersect_key($this->byRoot($traces), array_flip($rootIds));
    }

    /**
     * @return array<int, TrackingFacts> user id => facts, for every user who touched the title
     */
    public function forRoot(MediaItem $root): array
    {
        $byUser = [];

        foreach ($this->traces(items: $this->hierarchy->treeIdsQuery([$root->id])) as $trace) {
            $byUser[$trace['user']][] = $trace;
        }

        return array_map(fn (array $userTraces): TrackingFacts => $this->facts($userTraces, $root->id), $byUser);
    }

    /**
     * Traces grouped by the title they belong to, each group folded into facts.
     *
     * @param  list<array{user: int, item: int, trace: Trace}>  $traces
     * @return array<int, TrackingFacts>
     */
    private function byRoot(array $traces): array
    {
        $rootOf = $this->hierarchy->rootIdsByItem(array_unique(array_column($traces, 'item')));
        $byRoot = [];

        foreach ($traces as $trace) {
            $root = $rootOf[$trace['item']] ?? null;

            if ($root !== null) {
                $byRoot[$root][] = $trace;
            }
        }

        $facts = [];

        foreach ($byRoot as $rootId => $rootTraces) {
            $facts[$rootId] = $this->facts($rootTraces, $rootId);
        }

        return $facts;
    }

    /**
     * One title's traces folded into its facts. A status, a drop and a bell
     * speak for the title only on its root — a dropped season is a skipped
     * season, not a dropped show — while anything logged anywhere in the tree
     * is activity, and a backlog spot anywhere puts the whole title on it.
     *
     * @param  list<array{user: int, item: int, trace: Trace}>  $traces
     */
    private function facts(array $traces, int $rootId): TrackingFacts
    {
        $on = [];

        foreach ($traces as $trace) {
            $kind = $trace['trace'];

            if ($kind->isActivity()) {
                $on[Trace::Activity->value] = true;
            }

            if ($trace['item'] === $rootId || $kind === Trace::Backlog) {
                $on[$kind->value] = true;
            }
        }

        return new TrackingFacts(
            muted: isset($on[Trace::Mute->value]),
            onHold: isset($on[Trace::OnHold->value]),
            dropped: isset($on[Trace::Dropped->value]),
            backlog: isset($on[Trace::Backlog->value]),
            activity: isset($on[Trace::Activity->value]),
            manualFollow: isset($on[Trace::ManualFollow->value]),
            creatorFollow: isset($on[Trace::CreatorFollow->value]),
        );
    }

    /**
     * Every trace, narrowed to one user, to some items, or both — plays,
     * statuses, ratings, favorites, backlog spots and bells, as one query.
     *
     * @return list<array{user: int, item: int, trace: Trace}>
     */
    private function traces(?int $userId = null, ?Builder $items = null): array
    {
        $narrow = fn (Builder $query, string $userColumn, string $itemColumn): Builder => $query
            ->when($userId !== null, fn (Builder $q) => $q->where($userColumn, $userId))
            ->when($items !== null, fn (Builder $q) => $q->whereIn($itemColumn, $items));

        // A drop speaks only while it closes the current attempt, as the
        // status card reads it: it sits in the title's active cycle, no
        // completed play came after it there, and no new attempt is open on
        // top of it (a session logged since, as the API or an import may).
        // An older drop is history.
        $plays = $narrow(DB::table('plays'), 'user_id', 'media_item_id')
            ->distinct()
            ->select('user_id', 'media_item_id')
            ->selectRaw(
                'case when outcome = ? '
                .'and coalesce(watch_cycle_id, 0) = coalesce((select max(cycles.id) from watch_cycles as cycles '
                .'where cycles.user_id = plays.user_id and cycles.media_item_id = plays.media_item_id), 0) '
                .'and not exists (select 1 from plays as later where later.user_id = plays.user_id '
                .'and later.media_item_id = plays.media_item_id and later.outcome = ? '
                .'and coalesce(later.watch_cycle_id, 0) = coalesce(plays.watch_cycle_id, 0) '
                .'and (later.watched_at > plays.watched_at or (later.watched_at = plays.watched_at and later.id > plays.id))) '
                .'and not exists (select 1 from media_user_states as open where open.user_id = plays.user_id '
                .'and open.media_item_id = plays.media_item_id) '
                .'then ? else ? end as trace',
                [PlayOutcome::Dropped->value, PlayOutcome::Completed->value, Trace::Dropped->value, Trace::Activity->value],
            );

        $states = $narrow(DB::table('media_user_states'), 'user_id', 'media_item_id')
            ->select('user_id', 'media_item_id')
            ->selectRaw('case when status = ? then ? else ? end as trace', [MediaUserStatus::OnHold->value, Trace::OnHold->value, Trace::Activity->value]);

        $ratings = $narrow(DB::table('ratings'), 'user_id', 'media_item_id')
            ->select('user_id', 'media_item_id')
            ->selectRaw('? as trace', [Trace::Activity->value]);

        $favorites = $narrow(DB::table('favorites'), 'user_id', 'media_item_id')
            ->select('user_id', 'media_item_id')
            ->selectRaw('? as trace', [Trace::Activity->value]);

        $backlog = $narrow(DB::table('list_items'), 'lists.user_id', 'list_items.media_item_id')
            ->join('lists', 'lists.id', '=', 'list_items.list_id')
            ->where('lists.system_key', MediaList::SYSTEM_WATCHLIST)
            ->whereNotNull('list_items.media_item_id')
            ->select('lists.user_id', 'list_items.media_item_id')
            ->selectRaw('? as trace', [Trace::Backlog->value]);

        // A bell a followed person, company or collection rang carries a row
        // in one of the sponsor pivots; the user's own bell has none.
        $follows = $narrow(DB::table('follows'), 'follows.user_id', 'follows.media_item_id')
            ->whereNotNull('follows.media_item_id')
            ->select('follows.user_id', 'follows.media_item_id')
            ->selectRaw(
                'case when follows.mode = ? then ? '
                .'when exists (select 1 from follow_person where follow_person.follow_id = follows.id) '
                .'or exists (select 1 from follow_company where follow_company.follow_id = follows.id) '
                .'or exists (select 1 from follow_list where follow_list.follow_id = follows.id) then ? '
                .'else ? end as trace',
                [FollowMode::Mute->value, Trace::Mute->value, Trace::CreatorFollow->value, Trace::ManualFollow->value],
            );

        $rows = $plays->unionAll($states)->unionAll($ratings)->unionAll($favorites)->unionAll($backlog)->unionAll($follows)->get();

        $traces = [];

        foreach ($rows as $row) {
            $traces[] = ['user' => QueryRow::int($row, 'user_id'), 'item' => QueryRow::int($row, 'media_item_id'), 'trace' => Trace::from(QueryRow::text($row, 'trace'))];
        }

        return $traces;
    }
}

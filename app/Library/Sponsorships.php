<?php

namespace App\Library;

use App\Catalog\Creators\CreatorKind;
use App\Enums\FollowMode;
use App\Models\Follow;
use App\Models\User;
use App\Support\Numbers;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The bells a followed creator rings on its titles, and the one rule they live
 * by: an auto-follow stands as long as somebody backs it.
 *
 * A followed person, studio, network or collection SPONSORS the follow row of
 * each title it has coming. A second creator with the same title co-sponsors
 * the row instead of adding one; the user's own bell (a row nobody sponsors)
 * is never adopted. A mute is not a bell: whoever made it, a creator backs it
 * too, so lifting the mute leaves the bell the creator rings — muted first and
 * the creator followed later, or restored from a backup before the creator's
 * titles came in, alike. Withdrawing a creator removes its sponsorship, and
 * the row itself only once nobody is left behind it — a mute stays, it is the
 * user's.
 */
final readonly class Sponsorships
{
    /**
     * The creator backs these titles for the user: a title without a row gets
     * an auto-follow, a sponsored row or a mute (the mute stays) gains the
     * creator as a co-sponsor.
     *
     * @param  list<int>  $itemIds
     * @return list<int> the titles that got a row of their own
     */
    public function sponsor(User $user, CreatorKind $kind, int $creatorId, array $itemIds): array
    {
        if ($itemIds === []) {
            return [];
        }

        [$pivot, $column] = Follow::sponsorPivot($kind);

        return DB::transaction(function () use ($user, $kind, $creatorId, $itemIds, $pivot, $column): array {
            $existing = $user->follows()
                ->whereIn('media_item_id', $itemIds)
                ->withExists(['viaPeople', 'viaCompanies', 'viaLists'])
                ->withExists([Follow::sponsorRelation($kind).' as backed_by_creator' => fn (Builder $query) => $query->where("{$pivot}.{$column}", $creatorId)])
                ->get()
                ->keyBy('media_item_id');

            $created = array_values(array_diff($itemIds, $existing->keys()->all()));
            $now = now();

            Follow::query()->insert(array_map(fn (int $itemId): array => [
                'user_id' => $user->id,
                'media_item_id' => $itemId,
                'mode' => FollowMode::Follow->value,
                'created_at' => $now,
                'updated_at' => $now,
            ], $created));

            $newRows = $created === [] ? [] : Numbers::ints($user->follows()->whereIn('media_item_id', $created)->pluck('id'));

            $coSponsored = $existing
                ->filter(fn (Follow $follow): bool => ! $follow->getAttribute('backed_by_creator')
                    && ($follow->mode === FollowMode::Mute
                        || $follow->getAttribute('via_people_exists') || $follow->getAttribute('via_companies_exists') || $follow->getAttribute('via_lists_exists')))
                ->pluck('id');

            DB::table($pivot)->insert(array_map(
                fn (int $followId): array => ['follow_id' => $followId, $column => $creatorId],
                [...$newRows, ...Numbers::ints($coSponsored)],
            ));

            return $created;
        });
    }

    /**
     * The creator stops backing titles — for one user or for everyone, for
     * the given titles or all of them. Rows nobody backs any more go; a mute
     * and the user's own bell stay.
     *
     * @param  list<int>|null  $itemIds  null: every title the creator backs
     * @return int auto-follows removed
     */
    public function withdraw(CreatorKind $kind, int $creatorId, ?User $user = null, ?array $itemIds = null): int
    {
        [$pivot, $column] = Follow::sponsorPivot($kind);

        return DB::transaction(function () use ($kind, $creatorId, $user, $itemIds, $pivot, $column): int {
            $backed = Follow::query()
                ->when($user !== null, fn ($query) => $query->where('user_id', $user?->id))
                ->when($itemIds !== null, fn ($query) => $query->whereIn('media_item_id', $itemIds ?? []))
                ->whereHas(Follow::sponsorRelation($kind), fn ($query) => $query->where("{$pivot}.{$column}", $creatorId))
                ->pluck('id');

            if ($backed->isEmpty()) {
                return 0;
            }

            DB::table($pivot)->whereIn('follow_id', $backed)->where($column, $creatorId)->delete();

            return Numbers::int(Follow::query()
                ->whereIn('id', $backed)
                ->where('mode', FollowMode::Follow->value)
                ->unsponsored()
                ->delete()) ?? 0;
        });
    }

    /** Somebody — a person, a company, a collection — still backs this row. */
    public function isSponsored(Follow $follow): bool
    {
        return Follow::query()->whereKey($follow->id)->sponsored()->exists();
    }

    /** The row becomes the user's own: no creator backs it any more. */
    public function release(Follow $follow): void
    {
        // A company and a network share one pivot: each table once.
        $pivots = array_unique(array_map(fn (CreatorKind $kind): string => Follow::sponsorPivot($kind)[0], CreatorKind::cases()));

        foreach ($pivots as $pivot) {
            DB::table($pivot)->where('follow_id', $follow->id)->delete();
        }
    }
}

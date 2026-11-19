<?php

namespace App\Users;

use App\Models\User;
use App\Users\Data\ActivityFilters;
use Illuminate\Contracts\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\JoinClause;

/**
 * The filters of a user view that judge the title a card shows rather than
 * the entry behind it: the title search, the genre and the hide toggles.
 * They read a query joined to `media_items`; the search and the genre also
 * climb to its ancestors, which they join themselves.
 */
final readonly class TitleFilters
{
    private const PARENTS = 'media_items as parents';

    /**
     * The parent and grandparent of the joined item: a hierarchy is at most
     * three levels deep (show → season → episode). Joined once per query, so
     * a caller that reads the ancestors for its own reasons shares the join.
     *
     * @param  Builder<covariant Model>  $query
     */
    public function joinAncestors(Builder $query): void
    {
        $joined = collect($query->getQuery()->joins ?? [])->contains(fn (JoinClause $join): bool => $join->table === self::PARENTS);

        if ($joined) {
            return;
        }

        $query
            ->leftJoin(self::PARENTS, 'parents.id', '=', 'media_items.parent_id')
            ->leftJoin('media_items as grandparents', 'grandparents.id', '=', 'parents.parent_id');
    }

    /**
     * `$subject` is what the hide toggles look at: the item itself, or an
     * expression naming the container a card rolls up into.
     *
     * @param  Builder<covariant Model>  $query
     * @param  literal-string  $subject
     * @param  list<string>  $subjectBindings
     */
    public function apply(Builder $query, User $user, ActivityFilters $filters, string $subject, array $subjectBindings): void
    {
        if ($filters->genre !== null || $filters->q !== null) {
            $this->joinAncestors($query);
        }

        if ($filters->q !== null) {
            // Wildcard-escape the term so a literal % / _ typed by the user
            // stays literal; match the item or any ancestor's title.
            $like = '%'.addcslashes($filters->q, '\\%_').'%';

            $query->where(fn (Builder $sub) => $sub
                ->whereRaw('media_items.title like ? escape ?', [$like, '\\'])
                ->orWhereRaw('parents.title like ? escape ?', [$like, '\\'])
                ->orWhereRaw('grandparents.title like ? escape ?', [$like, '\\']));
        }

        if ($filters->genre !== null) {
            // Genres live in the ROOT item's metadata (episode → season →
            // show). SQLite's json_each scans the persisted genre names directly.
            $query
                ->whereRaw(<<<'SQL'
                    exists (
                        select 1 from json_each(coalesce(
                            case
                                when grandparents.id is not null then grandparents.metadata
                                when parents.id is not null then parents.metadata
                                else media_items.metadata
                            end, '{}'), '$.genres') as genre_entry
                        where genre_entry.value = ?
                    )
                    SQL, [$filters->genre]);
        }

        if ($filters->hideRated) {
            $query->whereNotExists(fn (QueryBuilder $sub) => $sub
                ->from('ratings as hidden_ratings')
                ->whereRaw("hidden_ratings.media_item_id = {$subject}", $subjectBindings)
                ->where('hidden_ratings.user_id', $user->id));
        }

        if ($filters->hideListed) {
            $query->whereNotExists(fn (QueryBuilder $sub) => $sub
                ->from('list_items')
                ->join('lists', 'lists.id', '=', 'list_items.list_id')
                ->whereRaw("list_items.media_item_id = {$subject}", $subjectBindings)
                ->where('lists.user_id', $user->id)
                ->whereNull('lists.system_key'));
        }
    }
}

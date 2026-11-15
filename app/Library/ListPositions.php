<?php

namespace App\Library;

use App\Models\User;
use App\Support\Numbers;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Where things stand on a user's lists, and in what order the lists stand.
 * Positions are written in single statements: appending reads the last
 * position and inserts in one step (two adds at once never share a place),
 * and a new manual order is one UPDATE, however many rows it moves.
 */
final readonly class ListPositions
{
    /**
     * Where a new custom list of the user goes: after every list they have.
     * The backlog keeps 0 — created lazily, it would otherwise land last
     * instead of leading the index; a reorder gives it a real place.
     */
    public function nextListPosition(User $user): int
    {
        return (Numbers::int($user->lists()->max('position')) ?? 0) + 1;
    }

    /**
     * Puts a position at the end of a list.
     *
     * @param  array<string, mixed>  $attributes  what goes on the list (media_item_id / person_id / company_id), plus timestamps of its own where it has them
     */
    public function append(int $listId, array $attributes): void
    {
        $now = now();
        $row = ['list_id' => $listId, 'created_at' => $now, 'updated_at' => $now, ...$attributes];

        DB::table('list_items')->insertUsing(
            [...array_keys($row), 'position'],
            DB::table('list_items')
                ->selectRaw(implode(', ', array_fill(0, count($row), '?')).', coalesce(max(position), 0) + 1', array_values($row))
                ->where('list_id', $listId),
        );
    }

    /**
     * A manual order: the named ids first, in the order named, then every row
     * the payload left out, in the order it had. Ids that are not among the
     * rows are ignored.
     *
     * @template TModel of Model
     *
     * @param  Collection<array-key, TModel>  $rows  keyed by id, in their current order
     * @param  list<int>  $ids
     * @return list<TModel>
     */
    public function ordered(Collection $rows, array $ids): array
    {
        $rest = $rows->all();
        $ordered = [];

        foreach ($ids as $id) {
            if (isset($rest[$id])) {
                $ordered[] = $rest[$id];
                unset($rest[$id]);
            }
        }

        return [...$ordered, ...array_values($rest)];
    }

    /**
     * Writes new positions of rows of one model — only the rows that move.
     *
     * @template TModel of Model
     *
     * @param  Collection<array-key, TModel>  $rows  keyed by id
     * @param  array<int, int>  $positions  id => position
     */
    public function save(Collection $rows, array $positions): void
    {
        $moved = array_filter(
            $positions,
            fn (int $position, int $id): bool => $rows->get($id)?->getAttribute('position') !== $position,
            ARRAY_FILTER_USE_BOTH,
        );

        $model = $rows->first();

        if ($moved === [] || $model === null) {
            return;
        }

        // Plain integers, so they go into the CASE as they are.
        $case = 'case id';

        foreach ($moved as $id => $position) {
            $case .= ' when '.(int) $id.' then '.(int) $position;
        }

        $model->newQuery()->whereKey(array_keys($moved))->update(['position' => DB::raw($case.' end')]);
    }
}

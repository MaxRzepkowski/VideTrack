<?php

namespace App\Library;

use App\Catalog\Creators\CreatorRef;
use App\Catalog\Creators\Creators;
use App\Catalog\Data\MediaSummary;
use App\Enums\FollowMode;
use App\Models\Follow;
use App\Models\ListItem;
use App\Models\User;
use App\Support\Numbers;

/**
 * What the user has done with each creator on a page: whether its bell is on
 * and which of their lists it sits on — the creator cards' counterpart of the
 * title states. Keyed by {@see CreatorRef::key()}; a creator the user never
 * touched has no row and no entry.
 *
 * The bell of a person or company is on or off; a muted collection does not
 * count as followed here (its page reads the mode itself).
 */
final readonly class CreatorStateQuery
{
    public function __construct(private Creators $creators) {}

    /**
     * @param  iterable<CreatorRef>  $creators
     * @return array<string, array{followed: bool, listIds: list<int>}>
     */
    public function of(User $user, iterable $creators): array
    {
        $rows = $this->creators->findMany($creators);

        if ($rows === []) {
            return [];
        }

        /** @var array<string, array<int, string>> $byFollowColumn column => row id => key */
        $byFollowColumn = [];
        /** @var array<string, array<int, string>> $byListColumn */
        $byListColumn = [];

        foreach ($rows as $key => $row) {
            $kind = $this->creators->kindOf($row);
            $byFollowColumn[Follow::creatorColumn($kind)][$row->id] = $key;

            $listColumn = ListItem::creatorColumn($kind);

            if ($listColumn !== null) {
                $byListColumn[$listColumn][$row->id] = $key;
            }
        }

        $followed = [];
        $listIds = [];

        foreach ($byFollowColumn as $column => $keys) {
            $ids = $user->follows()
                ->whereIn($column, array_keys($keys))
                ->where('mode', FollowMode::Follow->value)
                ->pluck($column);

            foreach (Numbers::ints($ids) as $id) {
                $followed[$keys[$id]] = true;
            }
        }

        foreach ($byListColumn as $column => $keys) {
            $entries = ListItem::query()
                ->whereIn('list_id', $user->lists()->select('id'))
                ->whereIn($column, array_keys($keys))
                ->get([$column, 'list_id']);

            foreach ($entries as $entry) {
                $id = Numbers::int($entry->getAttribute($column));

                if ($id !== null) {
                    $listIds[$keys[$id]][] = $entry->list_id;
                }
            }
        }

        $states = [];

        foreach (array_keys($rows) as $key) {
            $states[$key] = ['followed' => $followed[$key] ?? false, 'listIds' => $listIds[$key] ?? []];
        }

        return $states;
    }

    /**
     * The creators among the cards of a page.
     *
     * @param  iterable<MediaSummary>  $summaries
     * @return array<string, array{followed: bool, listIds: list<int>}>
     */
    public function ofSummaries(User $user, iterable $summaries): array
    {
        $creators = [];

        foreach ($summaries as $summary) {
            $creator = CreatorRef::fromSummary($summary);

            if ($creator !== null) {
                $creators[] = $creator;
            }
        }

        return $this->of($user, $creators);
    }
}

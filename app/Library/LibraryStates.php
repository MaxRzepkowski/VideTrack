<?php

namespace App\Library;

use App\Catalog\Data\MediaDetails;
use App\Catalog\Data\MediaSummary;
use App\Catalog\MediaItemSummaries;
use App\Catalog\MediaTypeRegistry;
use App\Library\Data\EpisodePointer;
use App\Library\Data\HistoryState;
use App\Library\Data\UserMediaState;
use App\Models\LibraryEntry;
use App\Models\MediaItem;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * The `userStates` page prop: one state per summary, keyed "type:ref", read
 * from the projection the writes keep. A position nobody persisted is
 * untouched. A persisted container the user has no history on has no entry,
 * but still counts its aired episodes and knows its first one; that rare
 * case is worked out live, by the same calculator the projection uses.
 */
final readonly class LibraryStates
{
    public function __construct(
        private MediaTypeRegistry $types,
        private LibraryStateCalculator $calculator,
        private PositionMarksQuery $marks,
    ) {}

    /**
     * Every card of a details page, extras included: those cards get live
     * actions too.
     *
     * @return array<string, UserMediaState>
     */
    public function resolveForDetails(User $user, MediaDetails $details): array
    {
        return $this->resolve($user, $details->cards());
    }

    /**
     * One persisted item's state — for an action that has to act on what the
     * user was shown, rather than re-deriving it by a rule of its own.
     */
    public function resolveItem(User $user, MediaItem $item): UserMediaState
    {
        $summary = MediaItemSummaries::summary($item);

        return $this->resolve($user, [$summary])[$summary->key()];
    }

    /**
     * @param  iterable<MediaSummary>  $summaries
     * @return array<string, UserMediaState>
     */
    public function resolve(User $user, iterable $summaries): array
    {
        $keys = [];

        foreach ($summaries as $summary) {
            $keys[$summary->key()] = [$summary->type, $summary->ref];
        }

        $items = $this->findItems($keys);
        $history = $this->history($user, collect($items)->keyBy(fn (MediaItem $item): int => $item->id));
        $marks = $this->marks->of($user, collect($items)->values());

        $states = [];

        foreach (array_keys($keys) as $key) {
            $item = $items[$key] ?? null;

            $states[$key] = $item === null
                ? UserMediaState::untouched()
                : $marks->stateOf($item->id, $history[$item->id]);
        }

        return $states;
    }

    /**
     * @param  Collection<int, MediaItem>  $items
     * @return array<int, HistoryState> by item id
     */
    private function history(User $user, Collection $items): array
    {
        $entries = LibraryEntry::query()
            ->where('user_id', $user->id)
            ->whereIn('media_item_id', $items->keys())
            ->get()
            ->keyBy(fn (LibraryEntry $entry): int => $entry->media_item_id);

        $pointers = $this->pointers($entries);
        $history = [];

        foreach ($entries as $itemId => $entry) {
            $history[$itemId] = HistoryState::fromEntry($entry, $pointers[$itemId] ?? null);
        }

        $live = $items
            ->reject(fn (MediaItem $item): bool => $entries->has($item->id) || $this->types->get($item->type)->playable);

        $history += $live->isEmpty() ? [] : $this->calculator->compute($user, $live);

        foreach ($items as $itemId => $item) {
            $history[$itemId] ??= HistoryState::untouched();
        }

        return $history;
    }

    /**
     * @param  Collection<int, LibraryEntry>  $entries
     * @return array<int, EpisodePointer> item id => the episode it is up to
     */
    private function pointers(Collection $entries): array
    {
        $nextIds = $entries->pluck('next_episode_id')->filter()->unique();

        if ($nextIds->isEmpty()) {
            return [];
        }

        $episodes = MediaItem::query()
            ->whereIn('id', $nextIds)
            ->with('parent:id,number')
            ->get(['id', 'parent_id', 'type', 'provider_ref', 'title', 'number'])
            ->keyBy('id');

        $pointers = [];

        foreach ($entries as $itemId => $entry) {
            $episode = $entry->next_episode_id === null ? null : $episodes->get($entry->next_episode_id);

            if ($episode !== null) {
                $pointers[$itemId] = EpisodePointer::at($episode, $itemId);
            }
        }

        return $pointers;
    }

    /**
     * @param  array<string, array{string, string}>  $keys
     * @return array<string, MediaItem>
     */
    private function findItems(array $keys): array
    {
        $groups = [];

        foreach ($keys as [$type, $ref]) {
            // A type whose module the instance switched off has no provider
            // to find it by: its title reads untouched until the module returns.
            if (! $this->types->has($type)) {
                continue;
            }

            $definition = $this->types->get($type);
            $groups["{$definition->provider}|{$type}"][] = $ref;
        }

        $items = [];

        foreach ($groups as $group => $refs) {
            [$provider, $type] = explode('|', $group);

            $found = MediaItem::query()
                ->where('provider', $provider)
                ->where('type', $type)
                ->whereIn('provider_ref', $refs)
                ->get();

            foreach ($found as $item) {
                $items[MediaSummary::keyOf($item->type, $item->provider_ref)] = $item;
            }
        }

        return $items;
    }
}

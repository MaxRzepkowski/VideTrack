<?php

namespace App\Catalog;

use App\Catalog\Contracts\SupportsHierarchy;
use App\Catalog\Creators\CreditRecords;
use App\Catalog\Data\MediaDetails;
use App\Catalog\Data\MediaSummary;
use App\Events\Media\TitlePersisted;
use App\Events\Media\TitleRefreshed;
use App\Models\MediaItem;
use App\Support\Numbers;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

/**
 * Persists provider data on first user interaction. Hierarchical
 * types are stored as the FULL tree of their root (show + every season +
 * every episode), so derived watched status, Up Next and the calendar read
 * the database alone. Already-persisted items short-circuit without any
 * provider call.
 */
final readonly class TitleStore
{
    public function __construct(
        private MediaTypeRegistry $types,
        private ProviderRegistry $providers,
        private CreditRecords $credits,
    ) {}

    public function persist(string $type, string $ref, string $locale): MediaItem
    {
        $definition = $this->types->get($type);

        $existing = $this->find($definition, $ref);

        if ($existing !== null) {
            return $existing;
        }

        [$rootDefinition, $rootRef] = $this->rootOf($definition, $ref);

        try {
            $this->persistTree($rootDefinition, $rootRef, $locale);
            $root = $this->find($rootDefinition, $rootRef);

            if ($root !== null) {
                Event::dispatch(new TitlePersisted($root->id, $root->type));
            }
        } catch (UniqueConstraintViolationException) {
            // A concurrent request persisted the same tree; the rows exist,
            // and that request announces them.
            $root = null;
        }

        $item = $definition === $rootDefinition && $root !== null ? $root : $this->find($definition, $ref);

        return $item ?? throw new ItemNotFoundException("Item [{$type}:{$ref}] not found at the provider.");
    }

    /**
     * Re-persists an already stored root from the provider (metadata refresh
     * job) — no short-circuit, new children (fresh episodes) get rows.
     * Freshness is bounded by the provider's own response cache TTL.
     *
     * Answers the stored parts the provider no longer lists — the topmost of
     * each gone branch. Only under a parent the provider listed children
     * for: an answer that came back with none says nothing about the rows
     * already stored. Whether they may go is the library's question.
     *
     * @return list<int>
     */
    public function refresh(MediaItem $root, string $locale): array
    {
        $definition = $this->types->get($root->type);

        $vanished = $this->persistTree($definition, $root->provider_ref, $locale);

        Event::dispatch(new TitleRefreshed($root->id));

        return $vanished;
    }

    /** A title already stored, without asking any provider; null when it is not. */
    public function stored(string $type, string $ref): ?MediaItem
    {
        return $this->find($this->types->get($type), $ref);
    }

    private function find(MediaTypeDefinition $definition, string $ref): ?MediaItem
    {
        return MediaItem::query()->where([
            'provider' => $definition->provider,
            'type' => $definition->code,
            'provider_ref' => $ref,
        ])->first();
    }

    /**
     * @return array{MediaTypeDefinition, string}
     */
    private function rootOf(MediaTypeDefinition $definition, string $ref): array
    {
        while ($definition->parentType !== null) {
            $provider = $this->providers->forType($definition);

            if (! $provider instanceof SupportsHierarchy) {
                break;
            }

            $parentRef = $provider->parentRef($definition->code, $ref);

            if ($parentRef === null) {
                break;
            }

            $definition = $this->types->get($definition->parentType);
            $ref = $parentRef;
        }

        return [$definition, $ref];
    }

    /**
     * Two phases on purpose: all provider fetches (slow and rate-limited)
     * happen BEFORE the transaction, so the write transaction itself is short. SQLite has a single writer; holding a
     * write transaction across provider calls would lock every other
     * connection (sessions, cache) out of the database for its whole duration.
     */
    /**
     * @return list<int> stored parts the provider no longer lists ({@see refresh()})
     */
    private function persistTree(MediaTypeDefinition $definition, string $ref, string $locale): array
    {
        $details = $this->providers->forType($definition)->details($definition->code, $ref, $locale);

        $branches = $this->fetchBranches($definition, $details, $locale);

        return DB::transaction(function () use ($definition, $details, $branches): array {
            // The root's total is its subtree's; a childless type (movie, game)
            // falls back to its own runtime.
            $root = $this->upsertDetails($definition, $details, $this->sumTotals($branches, $definition) ?? $details->runtime);

            // The source answers for the title again: it is no archive entry.
            if ($root->gone_at !== null) {
                $root->forceFill(['gone_at' => null])->save();
            }

            // Already-fetched data only — see the note above about HTTP inside
            // the write transaction.
            $this->credits->store($root, $details->cast, $details->crew);

            return $this->writeBranches($definition, $branches, $root);
        });
    }

    /**
     * Collects the full subtree from the provider without touching the
     * database: the root's children from its details, their children from
     * the provider's leaves — a batched answer, not a request per child. A
     * tree is root → child → leaf (show → season → episode).
     *
     * @return list<array{summary: MediaSummary, children: list<mixed>, total: int|null}>
     */
    private function fetchBranches(MediaTypeDefinition $rootDefinition, MediaDetails $details, string $locale): array
    {
        if ($rootDefinition->childType === null) {
            return [];
        }

        $definition = $this->types->get($rootDefinition->childType);
        $leaves = $this->leavesByParent($rootDefinition, $definition, $details->summary->ref, $locale);

        $branches = [];

        foreach ($details->children as $summary) {
            $grandchildren = array_map(
                fn (MediaSummary $leaf): array => ['summary' => $leaf, 'children' => [], 'total' => $leaf->runtime],
                $leaves[$summary->ref] ?? [],
            );

            $branches[] = [
                'summary' => $summary,
                'children' => $grandchildren,
                // Rolled up here, where the whole subtree is in hand: a season
                // totals its episodes, an episode counts its own runtime.
                'total' => $this->sumTotals($grandchildren, $definition) ?? $summary->runtime,
            ];
        }

        return $branches;
    }

    /**
     * The root's leaves keyed by the ref of the child each sits under; the
     * provider names the parent, so refs stay its own.
     *
     * @return array<string, list<MediaSummary>>
     */
    private function leavesByParent(MediaTypeDefinition $root, MediaTypeDefinition $child, string $ref, string $locale): array
    {
        $provider = $this->providers->forType($root);

        if ($child->childType === null || ! $provider instanceof SupportsHierarchy) {
            return [];
        }

        $grouped = [];

        foreach ($provider->leaves($root->code, $ref, $locale) as $leaf) {
            $parentRef = $provider->parentRef($child->childType, $leaf->ref);

            if ($parentRef !== null) {
                $grouped[$parentRef][] = $leaf;
            }
        }

        return $grouped;
    }

    /**
     * Total runtime of one level: the children's totals summed, NULL when not
     * a single child carries one — a runtime-less type (books, comics) must
     * stay empty rather than read as a zero-minute title.
     *
     * The special child (season 0) is left out, exactly like everywhere else a
     * root cascades over its children: if marking a show watched never touches
     * its specials, "how long the whole thing takes" must not count them
     * either. The special child still keeps a total of its own.
     *
     * @param  list<array{summary: MediaSummary, children: list<mixed>, total: int|null}>  $branches
     * @param  MediaTypeDefinition  $parent  type whose children these are
     */
    private function sumTotals(array $branches, MediaTypeDefinition $parent): ?int
    {
        $specials = $parent->specialsChildNumber;

        if ($specials !== null) {
            $branches = array_filter(
                $branches,
                fn (array $branch): bool => $branch['summary']->number !== $specials,
            );
        }

        $totals = array_filter(array_column($branches, 'total'), fn (?int $total): bool => $total !== null);

        return $totals === [] ? null : (int) array_sum($totals);
    }

    /**
     * @param  list<array{summary: MediaSummary, children: list<mixed>, total: int|null}>  $branches
     * @return list<int> stored children the branches no longer hold, at any depth
     */
    private function writeBranches(
        MediaTypeDefinition $parentDefinition,
        array $branches,
        MediaItem $parent,
    ): array {
        if ($parentDefinition->childType === null || $branches === []) {
            return [];
        }

        $definition = $this->types->get($parentDefinition->childType);
        $kept = [];
        $vanished = [];

        // One read for the level rather than one per child: a long show's
        // season holds hundreds of episodes, all inside the write transaction.
        $stored = MediaItem::query()
            ->where('provider', $definition->provider)
            ->where('type', $definition->code)
            ->whereIn('provider_ref', array_map(fn (array $branch): string => $branch['summary']->ref, $branches))
            ->get()
            ->keyBy('provider_ref');

        foreach ($branches as $branch) {
            $ref = $branch['summary']->ref;
            $item = $stored->get($ref) ?? new MediaItem($this->identity($definition, $ref));
            $child = $this->upsertSummary($item, $branch['summary'], $parent, $branch['total']);
            // A provider listing one child twice finds the row it just wrote.
            $stored->put($ref, $child);
            $kept[] = $child->id;

            /** @var list<array{summary: MediaSummary, children: list<mixed>, total: int|null}> $grandchildren */
            $grandchildren = $branch['children'];

            $vanished = [...$vanished, ...$this->writeBranches($definition, $grandchildren, $child)];
        }

        $gone = Numbers::ints(MediaItem::query()->where('parent_id', $parent->id)->whereNotIn('id', $kept)->pluck('id'));

        return [...$gone, ...$vanished];
    }

    private function upsertDetails(MediaTypeDefinition $definition, MediaDetails $details, ?int $totalRuntime): MediaItem
    {
        $summary = $details->summary;
        $itemMetadata = ItemMetadata::fromDetails($details);
        $metadata = $itemMetadata->toStored();

        return $this->persistItem(MediaItem::query()->firstOrNew($this->identity($definition, $summary->ref)), [
            'number' => $summary->number,
            'title' => $summary->title,
            'year' => $summary->year,
            'release_date' => $summary->releaseDate,
            'release_date_precision' => $summary->releasePrecision,
            // Denormalised out of `metadata`: the release calendar filters a
            // date RANGE on it, and a JSON key cannot carry an index.
            'home_release_date' => $itemMetadata->homeReleaseDate(),
            'runtime' => $details->runtime,
            'total_runtime' => $totalRuntime,
            'overview' => $details->overview,
            'poster_path' => $summary->posterPath,
            'localized_titles' => $details->localizedTitles,
            'backdrop_path' => $summary->backdropPath,
            'community_rating' => $summary->communityRating,
            'status' => $details->status,
            'metadata' => $metadata,
        ]);
    }

    /**
     * @param  MediaItem  $item  the stored row, or a new one carrying the identity
     */
    private function upsertSummary(
        MediaItem $item,
        MediaSummary $summary,
        MediaItem $parent,
        ?int $totalRuntime,
    ): MediaItem {
        return $this->persistItem($item, [
            'parent_id' => $parent->id,
            'number' => $summary->number,
            'title' => $summary->title,
            'year' => $summary->year,
            'release_date' => $summary->releaseDate,
            'release_date_precision' => $summary->releasePrecision,
            'runtime' => $summary->runtime,
            'total_runtime' => $totalRuntime,
            'overview' => $summary->overview,
            'poster_path' => $summary->posterPath,
            'backdrop_path' => $summary->backdropPath,
            'community_rating' => $summary->communityRating,
            'metadata' => ItemMetadata::fromSummary($summary)->toStored(),
        ]);
    }

    /**
     * Writes an item only when it is new or its data actually changed — a
     * refresh over thousands of unchanged titles then issues no UPDATE at all,
     * sparing the DB churn and the write-lock contention a blanket rewrite
     * would cause. `refreshed_at` moves only on a real change (nothing gates on
     * it, so it reads as "last changed"). The provider maps every field in a
     * deterministic order, so an unchanged row is never falsely dirty:
     * two consecutive persists produce byte-identical metadata.
     *
     * @param  MediaItem  $item  the stored row, or a new one carrying the identity
     * @param  array<string, mixed>  $attributes
     */
    private function persistItem(MediaItem $item, array $attributes): MediaItem
    {
        // The provider never sends what a module remembered on the row.
        if (array_key_exists('metadata', $attributes) && $item->exists) {
            $attributes['metadata'] = ItemMetadata::fromStored(is_array($attributes['metadata']) ? $attributes['metadata'] : null)
                ->keepingModulesOf(ItemMetadata::fromStored($item->metadata))
                ->toStored();
        }

        $item->fill($attributes);

        if (! $item->exists || $item->isDirty()) {
            $item->refreshed_at = now();
            $item->save();
        }

        return $item;
    }

    /**
     * @return array{provider: string, type: string, provider_ref: string}
     */
    private function identity(MediaTypeDefinition $definition, string $ref): array
    {
        return [
            'provider' => $definition->provider,
            'type' => $definition->code,
            'provider_ref' => $ref,
        ];
    }
}

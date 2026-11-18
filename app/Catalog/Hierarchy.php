<?php

namespace App\Catalog;

use App\Catalog\Data\CascadeLeaf;
use App\Catalog\Data\SubtreeNode;
use App\Models\MediaItem;
use App\Support\Numbers;
use App\Support\QueryRow;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Walks the media hierarchy (show → season → episode) both ways. Every climb
 * and every descent goes through here, so the two rules that come with them
 * live once: a cascade skips the specials child (season 0), and a parent
 * cycle in corrupt data fails loudly instead of spinning or stopping short.
 */
final readonly class Hierarchy
{
    /** Deeper than any real hierarchy; reaching it means a parent cycle. */
    private const MAX_DEPTH = 20;

    public function __construct(private MediaTypeRegistry $types) {}

    /**
     * The season a part sits in, read off its parent: a parent with a parent
     * of its own is a season and gives its number; a parent without one is
     * the title itself, and the part sits in no season.
     */
    public static function seasonNumber(MediaItem $parent): ?int
    {
        return $parent->parent_id === null ? null : $parent->number;
    }

    /**
     * The item and every ancestor above it, item first, root last. A parent
     * already loaded on a node is taken as it is — a run placing many
     * episodes of one show loads the show once.
     *
     * @return non-empty-list<MediaItem>
     */
    public function ancestry(MediaItem $item): array
    {
        $chain = [$item];
        $node = $item;

        while ($node->parent_id !== null) {
            $this->guard(count($chain), $item->id);
            $node = $node->relationLoaded('parent') && $node->parent !== null ? $node->parent : $node->parent()->firstOrFail();
            $chain[] = $node;
        }

        return $chain;
    }

    public function rootOf(MediaItem $item): MediaItem
    {
        $chain = $this->ancestry($item);

        return $chain[count($chain) - 1];
    }

    /**
     * The root reached through the `parent` relations already loaded on the
     * item (`parent.parent` covers every real hierarchy) — no query, for a
     * page that eager-loaded its items.
     */
    public function loadedRootOf(MediaItem $item): MediaItem
    {
        $node = $item;
        $depth = 1;

        while ($node->parent_id !== null && $node->parent !== null) {
            $this->guard($depth++, $item->id);
            $node = $node->parent;
        }

        return $node;
    }

    /**
     * The root above each item, climbing parent links on lightweight rows,
     * one query per level for the whole batch.
     *
     * @param  iterable<int>  $itemIds
     * @return array<int, int> item id => root id
     */
    public function rootIdsByItem(iterable $itemIds): array
    {
        /** @var array<int, int> $cursor item id => the node it has climbed to */
        $cursor = [];

        foreach ($itemIds as $id) {
            $cursor[(int) $id] = (int) $id;
        }

        $roots = [];
        $depth = 0;

        while ($cursor !== []) {
            $this->guard($depth++, (int) array_key_first($cursor));

            $parents = MediaItem::query()
                ->toBase()
                ->whereIn('id', array_unique($cursor))
                ->pluck('parent_id', 'id');

            foreach ($cursor as $origin => $node) {
                if (! $parents->has($node)) {
                    unset($cursor[$origin]);

                    continue;
                }

                $parent = Numbers::int($parents[$node]);

                if ($parent === null) {
                    $roots[$origin] = $node;
                    unset($cursor[$origin]);
                } else {
                    $cursor[$origin] = $parent;
                }
            }
        }

        return $roots;
    }

    /**
     * The distinct roots above the given items.
     *
     * @param  iterable<int>  $itemIds
     * @return Collection<int, int>
     */
    public function rootIds(iterable $itemIds): Collection
    {
        return collect($this->rootIdsByItem($itemIds))->unique()->values();
    }

    /**
     * Every item of several trees mapped to the root of its tree, the roots
     * included — one query per level for the whole batch.
     *
     * @param  iterable<int>  $rootIds
     * @return array<int, int> item id => root id
     */
    public function treesOf(iterable $rootIds): array
    {
        $rootOf = [];

        foreach ($rootIds as $id) {
            $rootOf[(int) $id] = (int) $id;
        }

        $level = $rootOf;
        $depth = 0;

        while ($level !== []) {
            $this->guard($depth++, (int) array_key_first($level));

            $children = MediaItem::query()
                ->toBase()
                ->whereIn('parent_id', array_keys($level))
                ->pluck('parent_id', 'id');

            $next = [];

            foreach ($children as $child => $parent) {
                $parentId = Numbers::int($parent);

                if ($parentId !== null) {
                    $next[(int) $child] = $level[$parentId];
                }
            }

            $rootOf += $next;
            $level = $next;
        }

        return $rootOf;
    }

    /**
     * Every item of several trees, the roots included, as a subquery of ids —
     * for narrowing a query to whole titles without loading their ids first.
     * One level per step of the deepest hierarchy the registry defines.
     *
     * @param  iterable<int>  $rootIds
     */
    public function treeIdsQuery(iterable $rootIds): Builder
    {
        $level = DB::table('media_items')->select('id')->whereIn('id', Numbers::ints($rootIds));
        $tree = clone $level;

        for ($depth = 1; $depth < $this->deepestHierarchy(); $depth++) {
            $level = DB::table('media_items')->select('id')->whereIn('parent_id', $level);
            $tree->unionAll($level);
        }

        return $tree;
    }

    /**
     * The item and everything under it, specials included: what a title's
     * data covers, as opposed to what a cascade touches.
     *
     * @return Collection<int, int>
     */
    public function subtreeIds(MediaItem $item): Collection
    {
        return $this->subtree($item)->map(fn (SubtreeNode $node): int => $node->id)->values();
    }

    /**
     * The item and everything a cascade from it reaches: the subtree less the
     * specials child of each container and whatever hangs below it.
     *
     * @return Collection<int, int>
     */
    public function cascadeIds(MediaItem $item): Collection
    {
        return $this->subtree($item)->filter(fn (SubtreeNode $node): bool => $node->inCascade)->map(fn (SubtreeNode $node): int => $node->id)->values();
    }

    /**
     * The playable leaves a cascade from the item operates on (a season's
     * episodes, every season's episodes of a show); a playable item is its
     * own leaf. Explicit interaction with the specials child itself still
     * reaches its episodes, since that child is then the root of the walk.
     *
     * @return Collection<int, MediaItem>
     */
    public function playableLeaves(MediaItem $item): Collection
    {
        if ($this->types->get($item->type)->playable) {
            return collect([$item]);
        }

        $ids = $this->subtree($item)
            ->filter(fn (SubtreeNode $node): bool => $node->inCascade && $this->types->get($node->type)->playable)
            ->pluck('id');

        return $ids->isEmpty() ? new Collection : MediaItem::query()->whereIn('id', $ids)->get();
    }

    /**
     * The same leaves as light rows ({@see playableLeaves()}), for the
     * questions that need only ids, parents and whether a leaf aired: a long
     * show has thousands, too many to hydrate as models for a yes or no.
     *
     * @return Collection<int, SubtreeNode>
     */
    public function playableNodes(MediaItem $item): Collection
    {
        return $this->subtree($item)
            ->filter(fn (SubtreeNode $node): bool => $node->inCascade && $this->types->get($node->type)->playable)
            ->values();
    }

    /**
     * The playable leaves a cascade from each container reaches, for many
     * containers at once and in hierarchy order: one query per level, and a
     * parent met twice (a show and its own season both asked for) is read
     * once. Specials children are left out as in every cascade.
     *
     * @param  Collection<int, MediaItem>  $containers
     * @return array<int, list<CascadeLeaf>> container id => its leaves; a container without any is absent
     */
    public function cascadeLeaves(Collection $containers): array
    {
        $today = today();

        /** @var list<array{node: CascadeLeaf, container: int}> $level */
        $level = $containers->map(fn (MediaItem $item): array => [
            'node' => CascadeLeaf::fromItem($item, $item->hasAired($today), []),
            'container' => $item->id,
        ])->values()->all();
        $leaves = [];
        /** @var array<int, list<stdClass>> $childrenByParent */
        $childrenByParent = [];
        $depth = 0;

        while ($level !== []) {
            $this->guard($depth++, $level[0]['node']->id);

            $parents = [];

            foreach ($level as $entry) {
                $definition = $this->types->get($entry['node']->type);

                if ($definition->childType !== null) {
                    $parents[$entry['node']->id][] = $entry;
                } elseif ($definition->playable) {
                    $leaves[$entry['container']][] = $entry['node'];
                }
            }

            $unfetched = array_diff(array_keys($parents), array_keys($childrenByParent));

            if ($unfetched !== []) {
                foreach ($unfetched as $parentId) {
                    $childrenByParent[$parentId] = [];
                }

                $rows = MediaItem::query()
                    ->whereIn('parent_id', $unfetched)
                    ->select('id', 'parent_id', 'type', 'provider_ref', 'title', 'number', 'runtime')
                    ->withAiredFlag($today)
                    ->toBase()
                    ->get();

                foreach ($rows as $row) {
                    $childrenByParent[QueryRow::int($row, 'parent_id')][] = $row;
                }
            }

            $level = [];

            foreach ($parents as $parentId => $entries) {
                $parentType = $entries[0]['node']->type;

                foreach ($childrenByParent[$parentId] as $row) {
                    $number = QueryRow::intOrNull($row, 'number');

                    if ($this->isSpecialsChild($parentType, $number)) {
                        continue;
                    }

                    foreach ($entries as $entry) {
                        $level[] = [
                            'node' => CascadeLeaf::fromRow($row, (bool) $row->aired, [...$entry['node']->path, $number ?? 0]),
                            'container' => $entry['container'],
                        ];
                    }
                }
            }
        }

        foreach ($leaves as $container => $list) {
            usort($list, CascadeLeaf::compare(...));
            $leaves[$container] = $list;
        }

        return $leaves;
    }

    /**
     * The subtree as lightweight rows, level by level, the item first.
     *
     * @return Collection<int, SubtreeNode>
     */
    public function subtree(MediaItem $item): Collection
    {
        $today = today();
        $root = new SubtreeNode(
            id: $item->id,
            parentId: $item->parent_id,
            type: $item->type,
            number: $item->number,
            aired: $item->hasAired($today),
            inCascade: true,
        );

        /** @var Collection<int, SubtreeNode> $nodes */
        $nodes = collect([$root]);
        $level = [$root->id => $root];
        $depth = 0;

        while ($level !== []) {
            $this->guard($depth++, $item->id);

            $children = MediaItem::query()
                ->whereIn('parent_id', array_keys($level))
                ->select('id', 'parent_id', 'type', 'number')
                ->withAiredFlag($today)
                ->toBase()
                ->get();

            $next = [];

            foreach ($children as $child) {
                $parentId = QueryRow::int($child, 'parent_id');
                $parent = $level[$parentId];
                $number = QueryRow::intOrNull($child, 'number');

                $node = new SubtreeNode(
                    id: QueryRow::int($child, 'id'),
                    parentId: $parentId,
                    type: QueryRow::text($child, 'type'),
                    number: $number,
                    aired: (bool) $child->aired,
                    inCascade: $parent->inCascade && ! $this->isSpecialsChild($parent->type, $number),
                );

                $nodes->push($node);
                $next[$node->id] = $node;
            }

            $level = $next;
        }

        return $nodes;
    }

    /**
     * Whether a child so numbered is its parent type's specials child (season
     * 0 of a show): outside the progression and out of every cascade. Read
     * from the registry, so a module declaring its own specials number needs
     * no change here.
     */
    public function isSpecialsChild(string $parentType, ?int $number): bool
    {
        $specials = $this->types->get($parentType)->specialsChildNumber;

        return $specials !== null && $number === $specials;
    }

    /** Whether the item itself is the specials child of its parent type. */
    public function isSpecials(MediaItem $item): bool
    {
        $parentType = $this->types->get($item->type)->parentType;

        return $parentType !== null && $this->types->has($parentType) && $this->isSpecialsChild($parentType, $item->number);
    }

    /** Levels in the deepest hierarchy a type defines: three for show → season → episode. */
    private function deepestHierarchy(): int
    {
        $deepest = 1;

        foreach ($this->types->all() as $type) {
            $levels = 1;

            for ($child = $type->childType; $child !== null; $child = $this->types->get($child)->childType) {
                $levels++;
            }

            $deepest = max($deepest, $levels);
        }

        return $deepest;
    }

    private function guard(int $depth, int $itemId): void
    {
        if ($depth >= self::MAX_DEPTH) {
            throw new HierarchyCycle("The hierarchy around media item [{$itemId}] is deeper than ".self::MAX_DEPTH.' levels; its parent links form a cycle.');
        }
    }
}

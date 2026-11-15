<?php

namespace App\Actions\Media;

use App\Catalog\Data\MediaSummary;
use App\Catalog\Hierarchy;
use App\Catalog\MediaItemSummaries;
use App\Library\LibraryHolders;
use App\Models\MediaItem;
use App\Models\User;
use Carbon\CarbonInterface;

/**
 * The archive page: every title in the user's library its data source no
 * longer has, most recently dropped first. The cards show what the user keeps
 * on each; removing all of it lets the next refresh delete the title.
 */
final readonly class BuildArchivedTitles
{
    public function __construct(
        private LibraryHolders $holders,
        private Hierarchy $hierarchy,
    ) {}

    /**
     * @return list<array{media: MediaSummary, goneAt: CarbonInterface|null}>
     */
    public function execute(User $user): array
    {
        $roots = MediaItem::query()
            ->whereNull('parent_id')
            ->whereNotNull('gone_at')
            ->orderByDesc('gone_at')
            ->get();

        // Whole trees count: a show is the user's when they hold any episode.
        $rootOf = $this->hierarchy->treesOf($roots->modelKeys());
        $held = array_flip($this->holders->heldBy($user->id, collect(array_keys($rootOf)))
            ->map(fn (int $id): int => $rootOf[$id])
            ->all());

        return array_values($roots
            ->filter(fn (MediaItem $root): bool => isset($held[$root->id]))
            ->map(fn (MediaItem $root): array => ['media' => MediaItemSummaries::summary($root), 'goneAt' => $root->gone_at])
            ->all());
    }
}

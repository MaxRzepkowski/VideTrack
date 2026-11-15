<?php

namespace App\Actions\Media;

use App\Catalog\MediaItemSummaries;
use App\Catalog\MediaTypeRegistry;
use App\Catalog\ProgressTotals;
use App\Enums\MediaUserStatus;
use App\Library\Data\PlayingEntry;
use App\Library\Data\UpNextOrderKeys;
use App\Models\MediaUserState;
use App\Models\User;
use Illuminate\Support\Facades\App;

/**
 * Flat progress-type titles the user is currently on (games with the manual
 * "playing" status) — the games' counterpart of Up Next's first-gap entries
 * Ordered the way the user ordered Up Next itself: one preference
 * runs every in-progress section of the dashboard.
 */
final readonly class ResolveNowPlaying
{
    public function __construct(
        private MediaTypeRegistry $types,
        private MediaItemSummaries $cards,
        private ProgressTotals $totals,
    ) {}

    /**
     * @return list<PlayingEntry>
     */
    public function execute(User $user): array
    {
        $codes = [];

        foreach ($this->types->all() as $definition) {
            if ($definition->progressUnit !== null) {
                $codes[] = $definition->code;
            }
        }

        if ($codes === []) {
            return [];
        }

        $rows = array_values(MediaUserState::query()
            ->where('user_id', $user->id)
            ->where('status', MediaUserStatus::InProgress)
            ->whereHas('mediaItem', fn ($query) => $query->whereIn('type', $codes))
            ->with('mediaItem')
            ->orderByDesc('updated_at')
            ->get()
            ->map(function (MediaUserState $state): array {
                $item = $state->mediaItem;
                $progress = $state->progress === null ? null : (int) $state->progress;
                $share = $this->totals->remainingShare($item, $progress);

                return [
                    'entry' => new PlayingEntry(
                        media: $this->cards->withProgress($item),
                        progress: $progress,
                        remainingShare: $share,
                        at: $state->updated_at?->toISOString(),
                    ),
                    'order' => new UpNextOrderKeys(
                        remaining: $share === null ? null : [$share],
                        released: $item->release_date?->format('Y-m-d'),
                        title: $item->titleIn(App::getLocale()),
                        activity: $state->updated_at?->toIso8601String(),
                    ),
                ];
            })
            ->all());

        $sort = $user->preferences->upNextSort();
        usort($rows, fn (array $a, array $b): int => $sort->compare($a['order'], $b['order']));

        return array_column($rows, 'entry');
    }
}

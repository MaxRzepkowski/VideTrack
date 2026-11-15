<?php

namespace App\Library;

use App\Catalog\Data\MediaSummary;
use App\Catalog\MediaTypeRegistry;
use App\Models\RecentView;
use App\Models\User;
use App\Support\Numbers;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * A user's browsing history of main titles — the data behind the "Recently
 * viewed" strip and the palette's opening rows. Seasons and episodes never
 * reach here (only the main-title pages record), so the history stays at the
 * title level.
 */
final readonly class RecentViews
{
    /** How many entries a user keeps; the daily cleanup trims the rest. */
    private const KEEP = 24;

    /** How many a strip shows. */
    private const SHOWN = 8;

    public function __construct(private MediaTypeRegistry $types) {}

    /** Upserts the view, bumping its timestamp. */
    public function record(User $user, MediaSummary $title): void
    {
        RecentView::query()->updateOrCreate(
            ['user_id' => $user->id, 'type' => $title->type, 'provider_ref' => $title->ref],
            ['title' => $title->title, 'poster_path' => $title->posterPath, 'viewed_at' => now()],
        );
    }

    /**
     * The newest first, minus `$except` — the title whose page is open sits
     * in its banner.
     *
     * @return list<MediaSummary>
     */
    public function recent(User $user, ?MediaSummary $except = null, int $limit = self::SHOWN): array
    {
        return array_values(RecentView::query()
            ->where('user_id', $user->id)
            // A type whose module is off has no page to go back to.
            ->whereIn('type', array_keys($this->types->all()))
            ->when($except !== null, fn (Builder $query) => $query->where(fn (Builder $query) => $query
                ->where('type', '!=', $except?->type)
                ->orWhere('provider_ref', '!=', $except?->ref)))
            ->orderByDesc('viewed_at')
            ->limit($limit)
            ->get()
            ->map(fn (RecentView $view): MediaSummary => new MediaSummary(
                type: $view->type,
                ref: $view->provider_ref,
                provider: $this->types->get($view->type)->provider,
                title: $view->title,
                posterPath: $view->poster_path,
            ))
            ->all());
    }

    /**
     * Trims every user's history to the newest KEEP entries.
     *
     * @return int rows removed
     */
    public function prune(): int
    {
        $removed = 0;

        $crowded = RecentView::query()
            ->select('user_id')
            ->groupBy('user_id')
            ->having(DB::raw('count(*)'), '>', self::KEEP)
            ->pluck('user_id');

        foreach ($crowded as $userId) {
            $keep = RecentView::query()
                ->where('user_id', $userId)
                ->orderByDesc('viewed_at')
                ->limit(self::KEEP)
                ->pluck('id');

            $removed += Numbers::int(RecentView::query()
                ->where('user_id', $userId)
                ->whereNotIn('id', $keep)
                ->delete()) ?? 0;
        }

        return $removed;
    }
}

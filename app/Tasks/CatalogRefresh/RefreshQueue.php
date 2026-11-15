<?php

namespace App\Tasks\CatalogRefresh;

use App\Catalog\MediaTypeRegistry;
use App\Models\ChangeCursor;
use App\Models\MediaItem;
use App\Models\TitleRefresh;
use App\Support\Numbers;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The background refresh's ledger: which roots wait to be asked, why, and
 * when each was last answered for, and how far each provider's list of
 * changes has been read. The one reader and writer of `title_refreshes` and
 * `change_cursors`. Only roots of the types this instance serves are ever
 * handed out: a module switched off leaves its rows waiting, untouched.
 */
final readonly class RefreshQueue
{
    /** Rows written per statement. */
    private const CHUNK = 500;

    public function __construct(private MediaTypeRegistry $types) {}

    /**
     * Marks roots as waiting for $reason. A root already waiting keeps the
     * more urgent reason and the earlier time; one put off to later waits
     * from now.
     *
     * @param  iterable<int, int>  $rootIds
     */
    public function markDue(iterable $rootIds, RefreshReason $reason): void
    {
        $now = now();

        foreach (collect($rootIds)->unique()->chunk(self::CHUNK) as $chunk) {
            $stored = TitleRefresh::query()->whereIn('media_item_id', $chunk->all())->get()->keyBy('media_item_id');
            $rows = [];

            foreach ($chunk as $rootId) {
                $row = $stored->get($rootId);
                $waiting = $row?->due_at !== null;

                $rows[] = [
                    'media_item_id' => $rootId,
                    'asked_at' => null,
                    'due_at' => $waiting && $row->due_at->lt($now) ? $row->due_at : $now,
                    'reason' => $reason->strongerOf($waiting ? $row->reason : null)->value,
                ];
            }

            // Only the wait is rewritten: a stored row keeps its asked_at.
            TitleRefresh::query()->upsert($rows, ['media_item_id'], ['due_at', 'reason']);
        }
    }

    /**
     * Marks the roots the lists of changes named and moves each list's place
     * on, as one write: a run broken before it asks the same window again,
     * one broken after finds the marks waiting.
     *
     * @param  list<int>  $rootIds
     * @param  list<array{provider: string, type: string, checked_until: CarbonInterface}>  $cursors
     */
    public function markChanged(array $rootIds, array $cursors): void
    {
        DB::transaction(function () use ($rootIds, $cursors): void {
            $this->markDue($rootIds, RefreshReason::Changed);

            if ($cursors !== []) {
                ChangeCursor::query()->upsert($cursors, ['provider', 'type'], ['checked_until']);
            }
        });
    }

    /** How far the provider's list of changes for the type has been read; null before its first reading. */
    public function checkedUntil(string $provider, string $type): ?CarbonImmutable
    {
        return ChangeCursor::query()->where(['provider' => $provider, 'type' => $type])->first()?->checked_until->toImmutable();
    }

    /**
     * The next roots to ask, most urgent first, without the providers whose
     * turn is over for now.
     *
     * @param  list<string>  $skipProviders
     * @return Collection<int, TitleRefresh> each with its `item`
     */
    public function take(int $limit, array $skipProviders): Collection
    {
        return $this->dueNow($skipProviders)
            ->with('item')
            ->orderByRaw($this->urgency())
            ->orderBy('due_at')
            ->orderBy('media_item_id')
            ->limit($limit)
            ->get();
    }

    /**
     * Whether anything waits now outside the skipped providers.
     *
     * @param  list<string>  $skipProviders
     */
    public function hasDue(array $skipProviders): bool
    {
        return $this->dueNow($skipProviders)->exists();
    }

    /**
     * How many roots wait now, by reason.
     *
     * @return array<string, int>
     */
    public function dueByReason(): array
    {
        $waiting = $this->dueNow([])
            ->selectRaw('reason, count(*) as waiting')
            ->groupBy('reason')
            ->pluck('waiting', 'reason');
        $due = [];

        foreach ($waiting as $reason => $count) {
            $due[(string) $reason] = Numbers::int($count) ?? 0;
        }

        return $due;
    }

    /** The provider answered for the root: it waits for nothing until marked again. */
    public function recordAsked(int $rootId): void
    {
        TitleRefresh::query()->upsert(
            [['media_item_id' => $rootId, 'asked_at' => now(), 'due_at' => null, 'reason' => null]],
            ['media_item_id'],
            ['asked_at', 'due_at', 'reason'],
        );
    }

    /** The root could not be asked now; it keeps its reason and waits until $until. */
    public function postpone(int $rootId, CarbonInterface $until): void
    {
        TitleRefresh::query()->whereKey($rootId)->update(['due_at' => $until]);
    }

    /**
     * The roots of one provider's type that have waited longest since they
     * were last asked (never asked counts from when the root was stored),
     * leaving out those already waiting.
     *
     * @return list<int>
     */
    public function longestUnasked(string $provider, string $type, int $limit): array
    {
        return Numbers::ints(MediaItem::query()
            ->leftJoin('title_refreshes', 'title_refreshes.media_item_id', '=', 'media_items.id')
            ->whereNull('media_items.parent_id')
            ->where('media_items.provider', $provider)
            ->where('media_items.type', $type)
            ->whereNull('title_refreshes.due_at')
            ->orderByRaw('coalesce(title_refreshes.asked_at, media_items.created_at)')
            ->orderBy('media_items.id')
            ->limit($limit)
            ->pluck('media_items.id'));
    }

    /**
     * @param  list<string>  $skipProviders
     * @return Builder<TitleRefresh>
     */
    private function dueNow(array $skipProviders): Builder
    {
        return TitleRefresh::query()
            ->where('due_at', '<=', now())
            ->whereHas('item', fn (Builder $item) => $item
                ->whereIn('type', $this->types->rootCodes())
                ->whereNotIn('provider', $skipProviders));
    }

    /** RefreshReason::rank() as SQL, so the order is the enum's alone. */
    private function urgency(): string
    {
        $cases = array_map(fn (RefreshReason $reason): string => "when '{$reason->value}' then {$reason->rank()}", RefreshReason::cases());

        return 'case reason '.implode(' ', $cases).' end';
    }
}

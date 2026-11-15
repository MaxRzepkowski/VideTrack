<?php

namespace App\Actions\Media;

use App\Catalog\AirSchedule;
use App\Catalog\MediaKind;
use App\Models\MediaItem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Backfills episode broadcast times from the air schedule onto the persisted
 * rows the calendar reads. Runs only from the background job, one show at a
 * time, and sets `release_time` on episode rows whose airing has a real slot.
 * Streaming premieres are deliberately left without a time.
 */
final readonly class SyncAirSchedule
{
    public function __construct(private AirSchedule $schedule) {}

    public function execute(MediaItem $show): void
    {
        if ($show->type !== MediaKind::Show->value) {
            return;
        }

        $times = $this->schedule->fetch($show);

        if ($times !== []) {
            $this->applyTimes($show, $times);
        }
    }

    /**
     * @param  array<string, string>  $times  season:number => ISO airstamp
     */
    private function applyTimes(MediaItem $show, array $times): void
    {
        /** @var Collection<int, MediaItem> $seasons */
        $seasons = MediaItem::query()->where('parent_id', $show->id)->get();
        $seasonNumbers = $seasons->mapWithKeys(fn (MediaItem $season): array => [$season->id => $season->number]);

        $episodes = MediaItem::query()
            ->whereIn('parent_id', $seasons->pluck('id'))
            ->get();

        DB::transaction(function () use ($episodes, $seasonNumbers, $times) {
            foreach ($episodes as $episode) {
                $key = $seasonNumbers[$episode->parent_id].':'.$episode->number;
                $airstamp = $times[$key] ?? null;

                if ($airstamp === null) {
                    continue;
                }

                $instant = Carbon::parse($airstamp);

                // Skip no-op writes so untouched episodes keep their timestamps.
                if ($episode->release_time?->equalTo($instant)) {
                    continue;
                }

                $episode->update(['release_time' => $instant]);
            }
        });
    }
}

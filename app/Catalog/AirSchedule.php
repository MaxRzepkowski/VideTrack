<?php

namespace App\Catalog;

use App\Catalog\Contracts\SupportsAirSchedule;
use App\Models\MediaItem;

/**
 * A show's broadcast times from the air schedule, with the schedule's id for
 * the show remembered on the show's metadata (a module key, kept across
 * provider refreshes) so the lookup runs once.
 */
final readonly class AirSchedule
{
    public function __construct(private SupportsAirSchedule $schedule) {}

    /**
     * Asks the schedule, matching the show and remembering the match the
     * first time. Empty when the schedule does not know the show.
     *
     * @return array<string, string> season:number => ISO-8601 airstamp
     */
    public function fetch(MediaItem $show): array
    {
        $id = $this->rememberedId($show) ?? $this->rememberMatch($show);

        return $id === null ? [] : $this->schedule->airTimes($id);
    }

    /**
     * What is already cached, never a live request. Null when nothing is.
     *
     * @return array<string, string>|null season:number => ISO-8601 airstamp
     */
    public function cached(MediaItem $show): ?array
    {
        $id = $this->rememberedId($show);

        return $id === null ? null : $this->schedule->cachedAirTimes($id);
    }

    private function rememberedId(MediaItem $show): ?int
    {
        $id = ItemMetadata::fromStored($show->metadata)->module($this->schedule->showIdKey());

        return is_int($id) && $id > 0 ? $id : null;
    }

    private function rememberMatch(MediaItem $show): ?int
    {
        $id = $this->schedule->findShow($show);

        if ($id !== null) {
            $show->update(['metadata' => ItemMetadata::fromStored($show->metadata)->withModule($this->schedule->showIdKey(), $id)->toStored()]);
        }

        return $id;
    }
}

<?php

namespace App\Tracking;

use App\Catalog\AirSchedule;
use App\Catalog\Data\MediaSummary;
use App\Catalog\MediaKind;
use App\Catalog\MediaTypeRegistry;
use App\Jobs\EnrichShowSchedule;
use App\Models\MediaItem;
use Illuminate\Support\Facades\Cache;

/**
 * Broadcast times of a show's episodes, for the show, season and episode
 * pages. Reads only what the schedule already cached, so a render NEVER asks
 * it live (that path throttles and would block the request). Any gap yields
 * an empty map: the page shows dates without times.
 */
final readonly class EpisodeTimes
{
    /** A page reloaded in a loop must not flood the queue with backfills. */
    private const WARM_ONCE_PER_SECONDS = 60;

    /**
     * How far ahead an episode without a time is looked for. The schedule
     * may file an episode's slot days after the provider lists the episode;
     * the calendar and the next-episode card show what is this near.
     */
    public const AWAITED_DAYS = 14;

    public function __construct(
        private AirSchedule $schedule,
        private MediaTypeRegistry $types,
    ) {}

    /**
     * @return array<int, string> episode number => ISO-8601 airstamp
     */
    public function ofSeason(string $showRef, int $seasonNumber): array
    {
        $times = [];

        foreach ($this->cached($showRef) as $key => $airstamp) {
            [$season, $number] = array_map('intval', explode(':', $key));

            if ($season === $seasonNumber) {
                $times[$number] = $airstamp;
            }
        }

        return $times;
    }

    /**
     * Times of particular episodes, which may sit in different seasons (the
     * show page's next and last aired cards).
     *
     * @param  list<MediaSummary>  $episodes
     * @return array<string, string> episode ref => ISO-8601 airstamp
     */
    public function ofEpisodes(string $showRef, array $episodes): array
    {
        if ($episodes === []) {
            return [];
        }

        $cached = $this->cached($showRef);
        $times = [];

        foreach ($episodes as $episode) {
            $airstamp = $cached["{$episode->seasonNumber}:{$episode->number}"] ?? null;

            if ($airstamp !== null) {
                $times[$episode->ref] = $airstamp;
            }
        }

        return $times;
    }

    /**
     * Queues the background backfill after a refresh, only when it can bring
     * something: the refresh changed the show's schedule (a new episode, a
     * moved date), or an episode near ahead still has no time while the
     * show's others have theirs. A show the schedule gives no slots
     * (streaming) has no times at all, so it is not asked about every day.
     */
    public function afterRefresh(MediaItem $show, bool $scheduleChanged): void
    {
        if ($show->type !== MediaKind::Show->value) {
            return;
        }

        if ($scheduleChanged || $this->awaitsTimes($show)) {
            EnrichShowSchedule::dispatch($show->id);
        }
    }

    private function awaitsTimes(MediaItem $show): bool
    {
        $episodes = fn () => MediaItem::query()->whereIn('parent_id', MediaItem::query()->select('id')->where('parent_id', $show->id));

        return $episodes()->whereNotNull('release_time')->exists()
            && $episodes()->whereNull('release_time')->whereBetween('release_date', [today()->toDateString(), today()->addDays(self::AWAITED_DAYS)->toDateString()])->exists();
    }

    /**
     * Queues the background backfill when nothing is cached for the show yet
     * (never matched to the schedule, or its episode list expired), so a later
     * visit finds the times. A show the schedule answered with no real slots
     * (streaming) counts as warm.
     */
    public function warmIfCold(string $showRef): void
    {
        $show = $this->show($showRef);

        if ($show === null || $this->schedule->cached($show) !== null) {
            return;
        }

        if (Cache::add("tvmaze-warm:{$show->id}", true, self::WARM_ONCE_PER_SECONDS)) {
            EnrichShowSchedule::dispatch($show->id);
        }
    }

    /**
     * @return array<string, string> season:number => ISO-8601 airstamp
     */
    private function cached(string $showRef): array
    {
        $show = $this->show($showRef);

        return $show === null ? [] : $this->schedule->cached($show) ?? [];
    }

    private function show(string $showRef): ?MediaItem
    {
        // A page warms and reads in the same request: one lookup serves both.
        return once(fn (): ?MediaItem => MediaItem::query()
            ->where('provider', $this->types->get('show')->provider)
            ->where('type', 'show')
            ->where('provider_ref', $showRef)
            ->first());
    }
}

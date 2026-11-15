<?php

namespace App\Actions\Media;

use App\Catalog\MediaItemSummaries;
use App\Library\Data\EpisodeSegment;
use App\Library\Data\UpNextEntry;
use App\Library\Data\UpNextOrderKeys;
use App\Library\EpisodeMarkers;
use App\Library\EpisodeState;
use App\Library\WatchedShows;
use App\Models\LibraryEntry;
use App\Models\MediaItem;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\App;
use LogicException;

/**
 * Up Next to Watch: for every show the user actively watches, the FIRST
 * unwatched aired episode in (season, episode) order — the "first gap",
 * never "last watched + 1". Specials (season 0) are skipped, dropped /
 * on-hold shows are excluded, and a fully caught-up show drops out of the
 * section until a new episode airs. The gap, the counters and the bar are
 * the projection's; the card adds what the catalogue says of the episode.
 */
final readonly class ResolveUpNext
{
    public function __construct(
        private WatchedShows $shows,
        private EpisodeMarkers $markers,
    ) {}

    /**
     * @param  Collection<int, MediaItem>|null  $roots  the shows to look in (the progress shelf adds the paused ones); the watched ones by default
     * @return list<UpNextEntry> entries in the user's chosen order (default: most recent play)
     */
    public function execute(User $user, ?Collection $roots = null): array
    {
        $roots = ($roots ?? $this->shows->of($user))->keyBy('id');

        if ($roots->isEmpty()) {
            return [];
        }

        $entries = LibraryEntry::query()
            ->where('user_id', $user->id)
            ->whereIn('media_item_id', $roots->keys())
            ->whereNotNull('next_episode_id')
            ->get()
            ->keyBy('media_item_id');

        // The few episodes the cards point at, in full: their summaries, their
        // season's poster, and what the catalogue marks them with.
        $episodes = MediaItem::query()
            ->whereIn('id', $entries->pluck('next_episode_id'))
            ->with('parent')
            ->get()
            ->keyBy('id');

        $markers = $this->markers->of($episodes->values(), $roots);
        $locale = App::getLocale();
        $shelf = [];

        foreach ($roots as $rootId => $root) {
            $entry = $entries->get($rootId);
            $episode = $entry === null ? null : $episodes->get((int) $entry->next_episode_id);
            $season = $episode?->parent;

            if ($entry === null || $episode === null || $season === null) {
                continue;
            }

            $shelf[] = [
                'order' => new UpNextOrderKeys(
                    // A show that declares no episode length compares by
                    // episode count alone.
                    remaining: [(int) $entry->remaining_minutes, (int) $entry->remaining_episodes],
                    released: $episode->release_date?->toDateTimeString(),
                    title: $root->titleIn($locale),
                    activity: $entry->last_played_at?->toDateTimeString(),
                ),
                'card' => new UpNextEntry(
                    show: MediaItemSummaries::summary($root),
                    episode: MediaItemSummaries::summary($episode, ['showTitle' => $root->titleIn($locale)], seasonNumber: $season->number),
                    poster: $season->poster_path ?? $root->posterIn($locale),
                    badge: $markers[$episode->id]['badge'] ?? null,
                    closes: $markers[$episode->id]['closes'] ?? null,
                    watchedEpisodes: self::counted($entry->watched_episodes),
                    skippedEpisodes: self::counted($entry->skipped_episodes),
                    airedEpisodes: self::counted($entry->aired_episodes),
                    remainingEpisodes: self::counted($entry->remaining_episodes),
                    remainingMinutes: self::counted($entry->remaining_minutes),
                    watchedMinutes: self::counted($entry->watched_minutes),
                    // "Episode 100 of 143" says something S06E02 does not.
                    position: self::counted($entry->position),
                    segments: array_map(
                        fn (array $segment): EpisodeSegment => new EpisodeSegment(EpisodeState::from($segment['state']), $segment['episodes']),
                        $entry->segments ?? [],
                    ),
                ),
            ];
        }

        $sort = $user->preferences->upNextSort();
        usort($shelf, fn (array $a, array $b): int => $sort->compare($a['order'], $b['order']));

        return array_column($shelf, 'card');
    }

    /**
     * A show's counter: the projection leaves them empty only for a specials
     * season, which has no progression of its own and is never a root here.
     */
    private static function counted(?int $value): int
    {
        return $value ?? throw new LogicException('A show with a next episode is counted by the library projection.');
    }
}

<?php

namespace App\Tracking\Releases;

use App\Catalog\MediaTypeDefinition;
use App\Catalog\MediaTypeRegistry;
use App\Catalog\ReleaseRegion;
use App\Catalog\ViewerRegion;
use App\Enums\FollowMode;
use App\Models\Follow;
use App\Models\MediaItem;
use App\Models\User;
use App\Support\QueryRow;
use App\Tracking\Surface;
use App\Tracking\TrackedTitles;
use App\Tracking\TrackingSource;
use Illuminate\Support\Collection;

/**
 * Every release in a window of the titles a surface carries for a user:
 * the tracking matrix picks the titles, each kind of title's
 * {@see ReleaseSource} finds its releases. Fed from the locally persisted
 * `media_items` alone — the refresh job keeps them fresh.
 */
final readonly class ReleaseFeed
{
    public function __construct(
        private MediaTypeRegistry $types,
        private TrackedTitles $tracked,
        private EpisodeReleases $episodes,
        private FilmReleases $films,
        private ProgressReleases $progress,
        private RelatedReleases $related,
    ) {}

    /**
     * @return Collection<int, ReleaseEntry> flat and ungrouped, a title's several releases each its own entry
     */
    public function entries(User $user, Surface $surface, string $from, string $to): Collection
    {
        $tracked = $this->tracked->of($user, $surface);
        // A card names the creators behind a title only when they are why it
        // is here: one the backlog or history carries reads as the bell does.
        $byCreators = array_keys(array_filter($tracked, fn (TrackingSource $source): bool => $source === TrackingSource::CreatorFollow));

        $window = new ReleaseWindow(
            viewer: $user,
            from: $from,
            to: $to,
            surface: $surface,
            approximateDates: $surface->showsApproximateDates(),
            region: ReleaseRegion::ofCountry(ViewerRegion::resolve($user, $user->preferredLocale())),
            followedVia: $this->followedVia($user, $byCreators),
        );

        $sources = [$this->episodes, $this->films, $this->progress, $this->related];
        $titles = [];
        $sourcesOfType = [];

        // Only the kind of each title here; each source reads the titles it
        // serves itself, as far as the window needs them.
        $rows = MediaItem::query()->toBase()->whereIn('id', array_keys($tracked))->orderBy('id')->get(['id', 'type']);

        foreach ($rows as $row) {
            $type = QueryRow::text($row, 'type');

            // A type whose module the instance switched off has no releases to tell.
            if (! $this->types->has($type)) {
                continue;
            }

            $sourcesOfType[$type] ??= $this->sourcesOf($this->types->get($type));

            foreach ($sources as $index => $source) {
                if (in_array($source, $sourcesOfType[$type], true)) {
                    $titles[$index][] = QueryRow::int($row, 'id');
                }
            }
        }

        /** @var Collection<int, ReleaseEntry> $entries */
        $entries = collect();

        foreach ($sources as $index => $source) {
            if (isset($titles[$index])) {
                $entries = $entries->concat($source->releases($titles[$index], $window));
            }
        }

        return $entries->values();
    }

    /**
     * Where a kind of title keeps its releases: its own, then the releases
     * of their own its provider lists beside it (a game's DLC). Empty for one
     * that has none.
     *
     * @return list<ReleaseSource>
     */
    public function sourcesOf(MediaTypeDefinition $type): array
    {
        $own = match (true) {
            $type->childType !== null => $this->episodes,
            $type->progressUnit !== null => $this->progress,
            $type->releasesWhole() => $this->films,
            default => null,
        };

        return array_values(array_filter([$own, $this->related->serves($type) ? $this->related : null]));
    }

    /**
     * Every sponsor's name behind an auto-followed title — followed people,
     * companies (studios, publishers, networks) AND collections — so a card
     * can say "because you follow X, Y".
     *
     * @param  array<int, int|string>  $rootIds
     * @return array<int, string> root id => names
     */
    private function followedVia(User $user, array $rootIds): array
    {
        return Follow::query()
            ->where('user_id', $user->id)
            ->where('mode', FollowMode::Follow->value)
            ->whereIn('media_item_id', $rootIds)
            ->sponsored()
            ->with(['viaPeople', 'viaCompanies', 'viaLists'])
            ->get()
            ->mapWithKeys(fn (Follow $follow) => [(int) $follow->media_item_id => $follow->sponsorNames()])
            ->filter()
            ->all();
    }
}

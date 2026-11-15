<?php

namespace App\Tracking\Releases;

use App\Catalog\MediaTypeDefinition;
use App\Catalog\MediaTypeRegistry;
use App\Enums\FollowMode;
use App\Models\Follow;
use App\Models\MediaItem;
use App\Models\User;
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
    ) {}

    /**
     * @return Collection<int, ReleaseEntry> flat and ungrouped, a title's several releases each its own entry
     */
    public function entries(User $user, Surface $surface, string $from, string $to): Collection
    {
        $tracked = $this->tracked->of($user, $surface);
        // A type whose module the instance switched off has no releases to tell.
        $roots = MediaItem::query()->whereIn('id', array_keys($tracked))->get()
            ->filter(fn (MediaItem $root): bool => $this->types->has($root->type));
        // A card names the creators behind a title only when they are why it
        // is here: one the backlog or history carries reads as the bell does.
        $byCreators = array_keys(array_filter($tracked, fn (TrackingSource $source): bool => $source === TrackingSource::CreatorFollow));

        $window = new ReleaseWindow(
            viewer: $user,
            from: $from,
            to: $to,
            mode: $user->preferences->releaseMode($surface),
            perPlatform: $user->preferences->perPlatform($surface),
            approximateDates: $surface->showsApproximateDates(),
            followedVia: $this->followedVia($user, $byCreators),
        );

        /** @var Collection<int, ReleaseEntry> $entries */
        $entries = collect();

        foreach ([$this->episodes, $this->films, $this->progress] as $source) {
            $titles = $roots->filter(fn (MediaItem $root): bool => $this->sourceOf($this->types->get($root->type)) === $source);

            if ($titles->isNotEmpty()) {
                $entries = $entries->concat($source->releases($titles->toBase()->values(), $window));
            }
        }

        return $entries->values();
    }

    /** Where a kind of title keeps its releases; null for one that has none of its own. */
    private function sourceOf(MediaTypeDefinition $type): ?ReleaseSource
    {
        return match (true) {
            $type->childType !== null => $this->episodes,
            $type->progressUnit !== null => $this->progress,
            $type->releasesWhole() => $this->films,
            default => null,
        };
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

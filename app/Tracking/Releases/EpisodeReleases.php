<?php

namespace App\Tracking\Releases;

use App\Accounts\Preferences\NotificationEvent;
use App\Accounts\Preferences\PreferenceKeys;
use App\Catalog\ItemMetadata;
use App\Catalog\MediaItemSummaries;
use App\Catalog\MediaTypeDefinition;
use App\Enums\PlayOutcome;
use App\Models\MediaItem;
use App\Models\Play;
use App\Tracking\Settings\TypeOption;
use App\Tracking\Surface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\App;

/**
 * A series' releases are its episodes airing in the window — specials
 * included: a special airing is still a release worth surfacing. Episodes of
 * a SKIPPED season (a dropped play on the season node) stay out: the user
 * deliberately settled that season.
 */
final readonly class EpisodeReleases implements ReleaseSource
{
    /**
     * Every tracked series shows its episodes in the views; whether one airing
     * pings is the series' own question.
     */
    public function typeOptions(MediaTypeDefinition $type): array
    {
        return [TypeOption::on(
            key: 'episodes',
            label: 'settings.new_episodes',
            description: 'settings.an_episode_of_a_tracked_show',
            phrase: 'settings.phrase_new_episodes',
            surfaces: [Surface::Notifications],
            preference: fn (): string => PreferenceKeys::notifies(NotificationEvent::Episodes),
        )];
    }

    public function releases(array $rootIds, ReleaseWindow $window): Collection
    {
        $episodes = MediaItem::query()
            ->whereIn('parent_id', MediaItem::query()->select('id')->whereIn('parent_id', $rootIds))
            ->whereNotIn('parent_id', Play::query()
                ->select('media_item_id')
                ->where('user_id', $window->viewer->id)
                ->where('outcome', PlayOutcome::Dropped->value))
            ->whereBetween('release_date', $window->storedBounds())
            ->orderBy('number')
            ->orderBy('id')
            ->get();

        if ($episodes->isEmpty()) {
            return new Collection;
        }

        $seasons = MediaItem::query()
            ->whereIn('id', $episodes->pluck('parent_id')->unique()->values())
            ->get(['id', 'parent_id', 'number'])
            ->keyBy('id');
        $shows = MediaItem::query()
            ->whereIn('id', $seasons->pluck('parent_id')->unique()->values())
            ->get()
            ->keyBy('id');

        return $episodes
            ->toBase()
            ->map(function (MediaItem $episode) use ($shows, $seasons, $window): ReleaseEntry {
                /** @var MediaItem $season the seasons were read by these episodes' parents */
                $season = $seasons->get($episode->parent_id);
                /** @var MediaItem $show a season's parent is one of the shows asked about */
                $show = $shows->get($season->parent_id);
                $showTitle = $show->titleIn(App::getLocale());
                $metadata = ItemMetadata::fromStored($episode->metadata);

                return new ReleaseEntry(
                    media: MediaItemSummaries::summary($episode, [
                        'showTitle' => $showTitle,
                        // Fallback thumbnail when the episode has no still.
                        'showBackdrop' => $show->backdrop_path,
                        // Portrait art for the dashboard's day panel: an
                        // episode never has one of its own.
                        'showPoster' => $show->posterIn(App::getLocale()),
                        ...$window->followedViaOf($show),
                    ], seasonNumber: $season->number, episodeType: $metadata->episodeType, episodeBadge: $metadata->episodeBadge),
                    rootId: $show->id,
                    rootType: $show->type,
                    showTitle: $showTitle,
                );
            })
            ->values();
    }
}

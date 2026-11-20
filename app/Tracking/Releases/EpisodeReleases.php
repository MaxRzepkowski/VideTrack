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

    public function releases(Collection $roots, ReleaseWindow $window): Collection
    {
        $shows = $roots->keyBy('id');

        $seasons = MediaItem::query()
            ->whereIn('parent_id', $shows->keys())
            ->get(['id', 'parent_id', 'number'])
            ->keyBy('id');

        $skippedSeasons = Play::query()
            ->where('user_id', $window->viewer->id)
            ->where('outcome', PlayOutcome::Dropped->value)
            ->whereIn('media_item_id', $seasons->keys())
            ->distinct()
            ->pluck('media_item_id');

        // Eloquent's except() filters by model keys but re-indexes the
        // collection — restore the id keying the lookups below rely on.
        $seasons = $seasons->except($skippedSeasons->all())->keyBy('id');

        return MediaItem::query()
            ->whereIn('parent_id', $seasons->keys())
            // release_date is stored midnight-stamped (Y-m-d 00:00:00), so a
            // bare Y-m-d upper bound would drop the window's last day.
            ->whereBetween('release_date', [$window->from, "{$window->to} 23:59:59"])
            ->orderBy('number')
            ->get()
            ->toBase()
            ->map(function (MediaItem $episode) use ($shows, $seasons, $window): ReleaseEntry {
                /** @var MediaItem $season the episodes were asked for by these seasons */
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

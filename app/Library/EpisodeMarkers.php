<?php

namespace App\Library;

use App\Catalog\EpisodeBadge;
use App\Catalog\EpisodeType;
use App\Catalog\Hierarchy;
use App\Catalog\ItemMetadata;
use App\Catalog\TitleStatus;
use App\Models\MediaItem;
use Illuminate\Support\Collection;

/**
 * What an Up Next card marks its episode with: a premiere or a finale, and
 * whether settling it runs the show out. Facts about the episode in its show,
 * whoever watches it — read from the catalogue for the few episodes a shelf
 * shows.
 */
final readonly class EpisodeMarkers
{
    public function __construct(private Hierarchy $hierarchy) {}

    /**
     * @param  Collection<int, MediaItem>  $episodes  each with its season loaded
     * @param  Collection<int, MediaItem>  $roots  the shows, by id
     * @return array<int, array{badge: EpisodeBadge|null, closes: ClosesRun|null}> by episode id
     */
    public function of(Collection $episodes, Collection $roots): array
    {
        if ($episodes->isEmpty()) {
            return [];
        }

        // A show's seasons in order, specials left out: they stand outside
        // the run whose first and last season the badges speak of.
        $seasonsByRoot = MediaItem::query()
            ->whereIn('parent_id', $episodes->pluck('parent.parent_id')->unique())
            ->orderBy('number')->orderBy('id')
            ->get(['id', 'parent_id', 'type', 'number'])
            ->reject(fn (MediaItem $season): bool => $this->hierarchy->isSpecials($season))
            ->groupBy('parent_id');

        $episodesBySeason = MediaItem::query()
            ->whereIn('parent_id', $episodes->pluck('parent_id')->unique())
            ->orderBy('number')->orderBy('id')
            ->get(['id', 'parent_id'])
            ->groupBy('parent_id');

        $markers = [];

        foreach ($episodes as $episode) {
            $season = $episode->parent;

            if ($season === null) {
                continue;
            }

            /** @var MediaItem $root */
            $root = $roots[$season->parent_id];
            $siblings = $episodesBySeason->get($season->id);
            $seasons = $seasonsByRoot->get($root->id);
            $place = [
                'type' => ItemMetadata::fromStored($episode->metadata)->episodeType,
                'first' => $siblings?->first()?->id === $episode->id,
                'last' => $siblings?->last()?->id === $episode->id,
                'firstSeason' => $seasons?->first()?->id === $season->id,
                'lastSeason' => $seasons?->last()?->id === $season->id,
            ];

            $markers[$episode->id] = [
                'badge' => $this->badge($root, $place),
                'closes' => $this->closes($root, $place),
            ];
        }

        return $markers;
    }

    /**
     * Premiere / finale badges. Premieres are positional within the season's
     * known episode list (not `number === 1` — TMDB keeps absolute numbering
     * on some saga-style seasons). Finales prefer TMDB's curated
     * `episode_type` (mid_season / finale); the positional "last known
     * episode" guess applies only where the provider gives no episode type — an
     * explicit "standard" suppresses it, so the newest episode of a running
     * season never masquerades as a finale.
     *
     * @param  array{type: EpisodeType|null, first: bool, last: bool, firstSeason: bool, lastSeason: bool}  $place
     */
    private function badge(MediaItem $root, array $place): ?EpisodeBadge
    {
        $ended = $root->status?->hasEnded() === true;

        return match (true) {
            $place['first'] && $place['firstSeason'] => EpisodeBadge::SeriesPremiere,
            $place['first'] => EpisodeBadge::SeasonPremiere,
            $place['type'] === EpisodeType::MidSeason => EpisodeBadge::MidSeasonFinale,
            $place['type'] === EpisodeType::Finale && $place['lastSeason'] && $ended => EpisodeBadge::SeriesFinale,
            $place['type'] === EpisodeType::Finale => EpisodeBadge::SeasonFinale,
            $place['type'] === null && $place['last'] && $place['lastSeason'] && $ended => EpisodeBadge::SeriesFinale,
            $place['type'] === null && $place['last'] => EpisodeBadge::SeasonFinale,
            default => null,
        };
    }

    /**
     * Whether settling this episode empties the show's watchable run: the
     * finale of an ended show ('series'), or the finale of the last known
     * season of a returning one ('season'). The dashboard holds such a card
     * in place for the rest of the visit instead of letting it vanish under
     * the cursor; every other way of running out — a mid-season break, or
     * simply catching up — lets the card disappear.
     *
     * Deliberately derived from the same facts as the badge rather than from
     * the badge itself: a final season of a single episode reads as a
     * premiere there, but it still closes the show out here.
     *
     * @param  array{type: EpisodeType|null, first: bool, last: bool, firstSeason: bool, lastSeason: bool}  $place
     */
    private function closes(MediaItem $root, array $place): ?ClosesRun
    {
        // A mid-season finale leaves the same season unfinished; an explicit
        // "standard" is the newest episode of a season still running.
        $lastOfSeason = $place['type'] === EpisodeType::Finale || ($place['type'] === null && $place['last']);

        if (! $lastOfSeason || ! $place['lastSeason']) {
            return null;
        }

        return match (true) {
            $root->status?->hasEnded() === true => ClosesRun::Series,
            $root->status === TitleStatus::ReturningSeries => ClosesRun::Season,
            default => null,
        };
    }
}

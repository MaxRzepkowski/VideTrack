<?php

namespace App\Integrations\Anime;

use App\Catalog\ItemMetadata;
use App\Catalog\MediaKind;
use App\Integrations\Export\DependsOnService;
use App\Integrations\Export\ExportStandings;
use App\Integrations\Export\ListStatus;
use App\Models\User;

/**
 * The user's anime as a MyAnimeList anime list: the shows' seasons as the
 * anime id map splits them, and the films the map knows by IMDb id. A show
 * or film the map does not know is not anime there and stays out, as does
 * one the map cannot tie to a single entry. Without the map nothing can be
 * placed, so there is no file.
 */
final class MalAnimeExport extends MalXmlExport implements DependsOnService
{
    public const CODE = 'myanimelist-anime';

    private const ANIME_LIST = 1;

    /** A film is one episode long. */
    private const FILM_EPISODES = 1;

    public function __construct(
        private readonly MalSeasonEntries $seasons,
        private readonly ExportStandings $standings,
        private readonly AnimeIdMap $map,
    ) {}

    public function code(): string
    {
        return self::CODE;
    }

    public function label(): string
    {
        return __('import.myanimelist_anime_list');
    }

    public function isAvailable(): bool
    {
        return $this->map->isAvailable();
    }

    protected function entryElement(): string
    {
        return ListMedium::Anime->value;
    }

    protected function exportType(): int
    {
        return self::ANIME_LIST;
    }

    protected function totals(int $entries, array $counts): array
    {
        return [
            'user_total_anime' => $entries,
            'user_total_watching' => $counts[ListStatus::Current->value],
            'user_total_completed' => $counts[ListStatus::Completed->value],
            'user_total_onhold' => $counts[ListStatus::OnHold->value],
            'user_total_dropped' => $counts[ListStatus::Dropped->value],
            'user_total_plantowatch' => $counts[ListStatus::Planned->value],
        ];
    }

    protected function fields(AnimeListEntry $entry, string $startedOn, string $finishedOn): array
    {
        return [
            'series_animedb_id' => $entry->malId,
            'series_title' => $entry->title,
            // Importers place an entry by its id; its kind is theirs to know.
            'series_type' => 'Unknown',
            'series_episodes' => $entry->total ?? 0,
            'my_id' => 0,
            'my_watched_episodes' => $entry->progress,
            'my_start_date' => $startedOn,
            'my_finish_date' => $finishedOn,
            'my_rated' => '',
            'my_score' => $entry->score ?? 0,
            'my_storage' => '',
            'my_storage_value' => '0.00',
            'my_status' => MalXmlStatus::of($entry->status, ListMedium::Anime)->value,
            'my_comments' => '',
            'my_times_watched' => 0,
            'my_rewatch_value' => '',
            'my_priority' => 'Low',
            'my_tags' => '',
            'my_rewatching' => 0,
            'my_rewatching_ep' => 0,
            'my_discuss' => 1,
            'my_sns' => 'default',
            'update_on_import' => self::KEEP_EXISTING,
        ];
    }

    protected function entries(User $user): array
    {
        $entries = $this->seasons->of($user);

        foreach ($this->standings->of($user, MediaKind::Movie) as $film) {
            $imdbId = ItemMetadata::fromStored($film->item->metadata)->imdbId();
            $malId = $imdbId === null ? null : $this->map->filmOf($imdbId);
            $status = $film->listStatus();

            if ($malId !== null && $status !== null) {
                $entries[] = new AnimeListEntry(
                    ListMedium::Anime,
                    $malId,
                    $film->item->title,
                    $status,
                    $film->rating?->rating,
                    $status === ListStatus::Completed ? self::FILM_EPISODES : 0,
                    self::FILM_EPISODES,
                    $film->startedAt(),
                    $status === ListStatus::Completed ? $film->entry?->watched_at : null,
                );
            }
        }

        return $entries;
    }
}

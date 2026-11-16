<?php

namespace App\Integrations\Anime;

use App\Catalog\ItemMetadata;
use App\Catalog\MediaKind;
use App\Catalog\ProgressUnit;
use App\Catalog\Providers\Mal\MalProvider;
use App\Enums\PlayOutcome;
use App\Integrations\Export\ExportStandings;
use App\Integrations\Export\ListStatus;
use App\Integrations\Export\TitleStanding;
use App\Models\User;

/**
 * The user's manga as a MyAnimeList manga list. A manga here is a
 * MyAnimeList title, so its ref is the entry's id. Chapters are what the
 * library counts: read so far on an open reading, the manga's own count on
 * a finished one, read before giving up on a dropped one. Volumes are not
 * kept, so none are written.
 */
final class MalMangaExport extends MalXmlExport
{
    public const CODE = 'myanimelist-manga';

    private const MANGA_LIST = 2;

    public function __construct(private readonly ExportStandings $standings) {}

    public function code(): string
    {
        return self::CODE;
    }

    public function label(): string
    {
        return __('import.myanimelist_manga_list');
    }

    protected function entryElement(): string
    {
        return ListMedium::Manga->value;
    }

    protected function exportType(): int
    {
        return self::MANGA_LIST;
    }

    protected function totals(int $entries, array $counts): array
    {
        return [
            'user_total_manga' => $entries,
            'user_total_reading' => $counts[ListStatus::Current->value],
            'user_total_completed' => $counts[ListStatus::Completed->value],
            'user_total_onhold' => $counts[ListStatus::OnHold->value],
            'user_total_dropped' => $counts[ListStatus::Dropped->value],
            'user_total_plantoread' => $counts[ListStatus::Planned->value],
        ];
    }

    protected function fields(AnimeListEntry $entry, string $startedOn, string $finishedOn): array
    {
        return [
            'manga_mangadb_id' => $entry->malId,
            'manga_title' => $entry->title,
            'manga_volumes' => 0,
            'manga_chapters' => $entry->total ?? 0,
            'my_id' => 0,
            'my_read_volumes' => 0,
            'my_read_chapters' => $entry->progress,
            'my_start_date' => $startedOn,
            'my_finish_date' => $finishedOn,
            'my_scanalation_group' => '',
            'my_score' => $entry->score ?? 0,
            'my_storage' => '',
            'my_retail_volumes' => 0,
            'my_status' => MalXmlStatus::of($entry->status, ListMedium::Manga)->value,
            'my_comments' => '',
            'my_times_read' => 0,
            'my_tags' => '',
            'my_priority' => 'Low',
            'my_reread_value' => '',
            'my_rereading' => 'NO',
            'my_discuss' => 'YES',
            'my_sns' => 'default',
            'update_on_import' => self::KEEP_EXISTING,
        ];
    }

    protected function entries(User $user): array
    {
        $entries = [];

        foreach ($this->standings->of($user, MediaKind::Manga) as $manga) {
            $status = $manga->listStatus();

            if ($status === null || $manga->item->provider !== MalProvider::CODE || ! ctype_digit($manga->item->provider_ref)) {
                continue;
            }

            $total = ItemMetadata::fromStored($manga->item->metadata)->progressTotal(ProgressUnit::Chapters);

            $entries[] = new AnimeListEntry(
                ListMedium::Manga,
                (int) $manga->item->provider_ref,
                $manga->item->title,
                $status,
                $manga->rating?->rating,
                $this->chapters($manga, $status, $total),
                $total,
                $manga->startedAt(),
                $status === ListStatus::Completed ? $manga->entry?->watched_at : null,
            );
        }

        return $entries;
    }

    /**
     * Chapters read, by the state the manga stands in. A finished manga was
     * read to its end, whatever a reading logged on the way; without a known
     * end, the last finished reading says how far that was.
     */
    private function chapters(TitleStanding $manga, ListStatus $status, ?int $total): int
    {
        return match ($status) {
            ListStatus::Current, ListStatus::OnHold => $manga->entry?->progress,
            ListStatus::Completed => $total ?? $manga->plays->where('outcome', PlayOutcome::Completed)->last()?->amount,
            ListStatus::Dropped => $manga->entry?->dropped_amount,
            ListStatus::Planned => null,
        } ?? 0;
    }
}

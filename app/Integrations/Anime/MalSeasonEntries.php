<?php

namespace App\Integrations\Anime;

use App\Accounts\UserCalendar;
use App\Catalog\ItemMetadata;
use App\Catalog\MediaKind;
use App\Enums\PlayOutcome;
use App\Enums\PositionStatus;
use App\Integrations\Export\ListStatus;
use App\Models\LibraryEntry;
use App\Models\MediaItem;
use App\Models\Play;
use App\Models\Rating;
use App\Models\User;
use App\Support\Numbers;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * The user's anime shows as MyAnimeList entries. MyAnimeList has no show,
 * only seasons and parts of seasons, each an anime of its own, so a show is
 * written as the parts the anime id map splits it into. A part is the
 * episodes of a season from where it starts to where the next part of that
 * season starts; TMDB and TVDB number the seasons alike, as the import
 * assumes when it reads the map the other way.
 *
 * What the library keeps of a season as a whole (its rating, its place on
 * the Backlog, a skip) goes only to a part that is the whole season: the
 * library does not say which part of a season a rating was for. What it
 * keeps of the show as a whole goes nowhere.
 *
 * @phpstan-type SeasonMarks array{rating: int|null, onBacklog: bool, skipped: bool}
 */
final readonly class MalSeasonEntries
{
    public function __construct(private AnimeIdMap $map) {}

    /**
     * @return list<AnimeListEntry>
     */
    public function of(User $user): array
    {
        $parts = [];

        foreach ($this->shows($user) as $show) {
            $tvdbId = ItemMetadata::fromStored($show->metadata)->tvdbId();
            $bySeason = $tvdbId === null ? [] : $this->map->seasonPartsOf($tvdbId);

            if ($bySeason !== []) {
                $parts[$show->id] = ['show' => $show, 'seasons' => $bySeason];
            }
        }

        $seasons = MediaItem::query()->whereIn('parent_id', array_keys($parts))->where('type', MediaKind::Season->value)
            ->orderBy('number')->get(['id', 'parent_id', 'number', 'title']);
        $seasonIds = $seasons->modelKeys();
        $episodeRows = MediaItem::query()->whereIn('parent_id', $seasonIds)->whereNotNull('number')
            ->select(['id', 'parent_id', 'number'])->withAiredFlag(UserCalendar::of($user)->today())->get();
        $episodes = $episodeRows->groupBy('parent_id');

        // Watched and when last, as the projection reads each episode; when
        // first is history the projection does not keep.
        $watched = LibraryEntry::query()->where('user_id', $user->id)->whereIn('media_item_id', $episodeRows->modelKeys())
            ->where('plays', '>', 0)->get(['media_item_id', 'watched_at'])->keyBy('media_item_id');
        $firstViewings = Play::query()->where('user_id', $user->id)->where('outcome', PlayOutcome::Completed->value)
            ->whereIn('media_item_id', $watched->keys()->all())->orderBy('watched_at')->get(['media_item_id', 'watched_at'])
            ->unique('media_item_id')->keyBy('media_item_id');
        $states = LibraryEntry::query()->where('user_id', $user->id)->whereIn('media_item_id', $seasonIds)->get()->keyBy('media_item_id');
        $ratings = Rating::query()->where('user_id', $user->id)->whereIn('media_item_id', $seasonIds)->get()->keyBy('media_item_id');
        $backlog = array_flip(Numbers::ints($user->watchlist()->items()->whereIn('media_item_id', $seasonIds)->pluck('media_item_id')));

        $entries = [];

        foreach ($parts as ['show' => $show, 'seasons' => $bySeason]) {
            foreach ($seasons->where('parent_id', $show->id) as $season) {
                $seasonParts = $season->number === null ? [] : $bySeason[$season->number] ?? [];
                $state = $states->get($season->id);
                $marks = count($seasonParts) === 1 && $seasonParts[0]->offset === 0 ? [
                    'rating' => $ratings->get($season->id)?->rating,
                    'onBacklog' => isset($backlog[$season->id]),
                    'skipped' => $state?->status === PositionStatus::Dropped && ! $state->status_inherited,
                ] : null;

                foreach ($seasonParts as $index => $part) {
                    $last = isset($seasonParts[$index + 1]) ? $seasonParts[$index + 1]->offset : null;
                    $range = ($episodes->get($season->id) ?? new Collection)
                        ->filter(fn (MediaItem $episode): bool => $episode->number > $part->offset && ($last === null || $episode->number <= $last));
                    $label = "{$show->title} – {$season->title}";
                    $end = $last ?? Numbers::int($range->max('number'));
                    $label = $marks === null ? $label.' ('.($part->offset + 1).'–'.$end.')' : $label;
                    $entry = $this->entry($label, $part, $range, $watched, $firstViewings, $state?->status, $marks);

                    if ($entry !== null) {
                        $entries[] = $entry;
                    }
                }
            }
        }

        return $entries;
    }

    /**
     * @param  Collection<int, MediaItem>  $range
     * @param  Collection<int, LibraryEntry>  $watched  the episodes watched, by id
     * @param  Collection<int, Play>  $firstViewings  each watched episode's first viewing, by id
     * @param  SeasonMarks|null  $marks  what the library keeps of the season, when the part is all of it
     */
    private function entry(string $title, MalSeasonPart $part, Collection $range, Collection $watched, Collection $firstViewings, ?PositionStatus $seasonStatus, ?array $marks): ?AnimeListEntry
    {
        $seen = $range->filter(fn (MediaItem $episode): bool => $watched->has($episode->id));
        $completed = $range->isNotEmpty() && $seen->count() === $range->count()
            && $range->every(fn (MediaItem $episode): bool => (bool) $episode->getAttribute('aired'));
        $status = $this->status($seen->isNotEmpty(), $completed, $seasonStatus, $marks);

        if ($status === null) {
            return null;
        }

        $started = $seen->map(fn (MediaItem $episode): ?CarbonImmutable => $firstViewings->get($episode->id)?->watched_at->toImmutable())->filter()->sort();
        $finished = $seen->map(fn (MediaItem $episode): ?CarbonImmutable => $watched->get($episode->id)?->watched_at)->filter()->sort();

        return new AnimeListEntry(
            ListMedium::Anime,
            $part->malId,
            $title,
            $status,
            $marks['rating'] ?? null,
            $completed ? $range->count() : $seen->count(),
            $range->count(),
            $started->first(),
            $completed ? $finished->last() : null,
        );
    }

    /**
     * A part watched to its end is completed; one under way reads what its
     * season reads (dropped or paused with the show, or on its own), else
     * watching. A part not begun is listed only as the whole season it is:
     * skipped, or waiting on the Backlog. A season only rated stays out:
     * unlike a film's, its viewing would be episodes the library knows
     * nothing of.
     *
     * @param  SeasonMarks|null  $marks
     */
    private function status(bool $started, bool $completed, ?PositionStatus $seasonStatus, ?array $marks): ?ListStatus
    {
        return match (true) {
            $completed => ListStatus::Completed,
            $started => match ($seasonStatus) {
                PositionStatus::Dropped => ListStatus::Dropped,
                PositionStatus::OnHold => ListStatus::OnHold,
                default => ListStatus::Current,
            },
            $marks !== null && $marks['skipped'] => ListStatus::Dropped,
            $marks !== null && $marks['onBacklog'] => ListStatus::Planned,
            default => null,
        };
    }

    /**
     * Every show the user has a word about on one of its positions: history,
     * or a season rated or on the Backlog.
     *
     * @return Collection<int, MediaItem>
     */
    private function shows(User $user): Collection
    {
        $markedSeasons = MediaItem::query()->select('parent_id')->where('type', MediaKind::Season->value)
            ->where(fn (Builder $query) => $query
                ->whereIn('id', Rating::query()->select('media_item_id')->where('user_id', $user->id))
                ->orWhereIn('id', $user->watchlist()->items()->select('media_item_id')));

        return MediaItem::query()
            ->where('type', MediaKind::Show->value)
            ->where(fn (Builder $query) => $query
                ->whereIn('id', LibraryEntry::query()->ofRoots()->select('media_item_id')->where('user_id', $user->id))
                ->orWhereIn('id', $markedSeasons))
            ->orderBy('title')
            ->orderBy('id')
            ->get(['id', 'title', 'metadata']);
    }
}

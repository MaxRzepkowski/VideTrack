<?php

namespace App\Actions\Users;

use App\Actions\Media\ResolveNowPlaying;
use App\Actions\Media\ResolveUpNext;
use App\Catalog\Data\MediaSummary;
use App\Catalog\Data\TypeCount;
use App\Catalog\MediaItemSummaries;
use App\Catalog\MediaTypeRegistry;
use App\Catalog\ProgressTotals;
use App\Enums\MediaUserStatus;
use App\Enums\ProgressSort;
use App\Enums\SortDirection;
use App\Library\Data\EpisodePointer;
use App\Library\Data\PlayingEntry;
use App\Library\Data\UpNextEntry;
use App\Library\Data\UserMediaState;
use App\Library\LibraryStates;
use App\Library\WatchedShows;
use App\Models\MediaItem;
use App\Models\MediaUserState;
use App\Models\User;
use App\Users\Data\ProgressFilters;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * The "in progress" panel of a user view: two shelves, what they are in the
 * middle of and what they paused. It reads no log at all — what someone is
 * in the middle of derives from the library projection — so it shares nothing with
 * the history / ratings / favorites pipeline.
 *
 * Progress and the next episode belong to the PROFILE user, while the card
 * action bars still reflect the viewer (`userStates`).
 *
 * @phpstan-type ProgressEntry array{id: null, at: string|null, media: MediaSummary, section: string, upNext?: UpNextEntry|null, watchedEpisodes?: int|null, airedEpisodes?: int|null, remainingEpisodes?: int|null, position?: int|null, nextEpisode?: EpisodePointer|null, skippedEpisodes?: int|null, remainingMinutes?: int|null, watchedMinutes?: int|null, progress?: int|null, remainingShare?: float|null}
 */
final readonly class BuildProgressPanel
{
    public function __construct(
        private WatchedShows $shows,
        private LibraryStates $states,
        private ResolveNowPlaying $nowPlaying,
        private ResolveUpNext $upNext,
        private MediaTypeRegistry $types,
        private MediaItemSummaries $cards,
        private ProgressTotals $totals,
    ) {}

    /**
     * @return array{entries: list<ProgressEntry>, types: list<TypeCount>, filters: array<string, string|null>, userStates: array<string, mixed>}
     */
    public function execute(User $viewer, User $user, ProgressFilters $filters): array
    {
        $watched = $this->shows->of($user);

        $summaries = $watched
            ->map(fn (MediaItem $root) => MediaItemSummaries::summary($root))
            ->values();

        $profileStates = $this->states->resolve($user, $summaries);

        $held = $this->heldStates($user);

        // A show being watched IS the episode it is up to — the shelves carry
        // the dashboard's card, not the series. Both shelves resolve in one
        // pass; on-hold shows are kept out of Up Next by their state row, so
        // their roots have to be handed over explicitly.
        $episodes = collect($this->upNext->execute($user, $watched->concat($held->map(fn (MediaUserState $state) => $state->mediaItem))))
            ->keyBy(fn (UpNextEntry $entry) => $entry->show->key());

        $entries = $summaries
            ->map(/** @return ProgressEntry|null */ function (MediaSummary $summary) use ($profileStates, $episodes): ?array {
                $state = $profileStates[$summary->key()] ?? null;

                if ($state === null || ! $state->inProgress) {
                    return null;
                }

                return [
                    'id' => null,
                    'at' => $state->watchedAt,
                    'media' => $summary,
                    'section' => MediaUserStatus::InProgress->value,
                    'upNext' => $episodes[$summary->key()] ?? null,
                    'watchedEpisodes' => $state->watchedEpisodes,
                    'airedEpisodes' => $state->airedEpisodes,
                    'remainingEpisodes' => $state->remainingEpisodes,
                    'position' => $state->position,
                    'nextEpisode' => $state->nextEpisode,
                    'skippedEpisodes' => $state->skippedEpisodes,
                    'remainingMinutes' => $state->remainingMinutes,
                    'watchedMinutes' => $state->watchedMinutes,
                ];
            })
            ->filter();

        // Games (flat progress types) with the "playing" status join the
        // shows; their progress is the session hour counter.
        $playing = collect($this->nowPlaying->execute($user))
            ->map(fn (PlayingEntry $entry): array => [
                'id' => null,
                'at' => $entry->at,
                'media' => $entry->media,
                'section' => MediaUserStatus::InProgress->value,
                'progress' => $entry->progress,
                'remainingShare' => $entry->remainingShare,
            ]);

        $all = $entries->concat($playing)->sortByDesc(fn (array $entry): ?int => $this->moment($entry['at']))->values()
            ->concat($this->onHoldEntries($user, $held, $episodes))
            ->all();

        // Counted before the search, so the numbers describe the whole set
        // rather than the current query; only what is on the shelves.
        $counts = [];

        foreach ($all as $entry) {
            $counts[$entry['media']->type] = ($counts[$entry['media']->type] ?? 0) + 1;
        }

        $type = $filters->type !== null && isset($counts[$filters->type]) ? $filters->type : null;

        $filtered = array_values(array_filter(
            $all,
            fn (array $entry) => ($type === null || $entry['media']->type === $type)
                && ($filters->status === null || $entry['section'] === $filters->status->value)
                && ($filters->q === null || $this->matches($entry, $filters->q)),
        ));

        $this->sort($filtered, $filters);

        // The episode cards act on the episode, so the viewer's state for it
        // has to travel along with the shelf's own media.
        $shown = array_filter(array_map(fn (array $entry) => ($entry['upNext'] ?? null)?->episode, $filtered));

        return [
            'entries' => $filtered,
            'types' => TypeCount::inOrder($counts, $this->types),
            'filters' => [
                'type' => $type,
                'status' => $filters->status?->value,
                'q' => $filters->q,
                'sort' => $filters->sort->value,
                'dir' => $filters->dir->value,
            ],
            'userStates' => $this->states->resolve(
                $viewer,
                [...array_map(fn (array $entry) => $entry['media'], $filtered), ...array_values($shown)],
            ),
        ];
    }

    /**
     * A search hits the show's title as well as the episode the card carries,
     * so typing a series name finds the card standing for it.
     *
     * @param  ProgressEntry  $entry
     */
    private function matches(array $entry, string $query): bool
    {
        $candidates = [$entry['media']->title, ($entry['upNext'] ?? null)?->episode->title];

        foreach ($candidates as $candidate) {
            if ($candidate !== null && mb_stripos($candidate, $query) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * An entry's last activity as an instant. Shows and games write it in
     * different offsets (the app's zone, UTC), so their strings do not sort.
     */
    private function moment(mixed $at): ?int
    {
        return is_string($at) ? CarbonImmutable::parse($at)->getTimestamp() : null;
    }

    /**
     * Each axis compares in its own natural direction — freshest, least left,
     * A→Z — and 'desc' turns the result around, the way every other listing
     * view reads its direction toggle.
     *
     * @param  list<ProgressEntry>  $entries
     */
    private function sort(array &$entries, ProgressFilters $filters): void
    {
        usort($entries, fn (array $a, array $b): int => match ($filters->sort) {
            ProgressSort::Title => strnatcasecmp($a['media']->title, $b['media']->title),
            ProgressSort::Remaining => $this->compareShares($this->remainingShare($a), $this->remainingShare($b)),
            // Newest first, the ones never touched last.
            ProgressSort::Activity => [$b['at'] !== null, $this->moment($b['at'])] <=> [$a['at'] !== null, $this->moment($a['at'])],
        });

        if ($filters->dir === SortDirection::Desc) {
            $entries = array_reverse($entries);
        }
    }

    /**
     * How much of the title is still ahead, as a share of the whole: a show
     * counts its unsettled aired episodes, a counted title the share its
     * entry brought ({@see ProgressTotals::remainingShare()}). Null where there
     * is nothing to measure against — those sort last either way.
     *
     * @param  ProgressEntry  $entry
     */
    private function remainingShare(array $entry): ?float
    {
        $aired = $entry['airedEpisodes'] ?? null;

        if ($aired !== null && $aired > 0) {
            return ($entry['remainingEpisodes'] ?? 0) / $aired;
        }

        return $entry['remainingShare'] ?? null;
    }

    private function compareShares(?float $a, ?float $b): int
    {
        return match (true) {
            $a === null && $b === null => 0,
            $a === null => 1,
            $b === null => -1,
            default => $a <=> $b,
        };
    }

    /**
     * Every paused open attempt of the profile user.
     *
     * @return Collection<int, MediaUserState>
     */
    private function heldStates(User $user): Collection
    {
        return MediaUserState::query()
            ->where('user_id', $user->id)
            ->where('status', MediaUserStatus::OnHold)
            ->with('mediaItem')
            ->latest('updated_at')
            ->get()
            ->values();
    }

    /**
     * The "on hold" shelf, with the same counters and episode cards the
     * in-progress ones carry.
     *
     * @param  Collection<int, MediaUserState>  $held
     * @param  Collection<string, UpNextEntry>  $episodes  up-next entries keyed "type:ref" of the show
     * @return list<ProgressEntry>
     */
    private function onHoldEntries(User $user, Collection $held, Collection $episodes): array
    {
        $states = $held->values()->all();
        $summaries = array_map(fn (MediaUserState $state): MediaSummary => $this->cards->withProgress($state->mediaItem), $states);
        $heldStates = $this->states->resolve($user, $summaries);
        $entries = [];

        foreach ($states as $index => $state) {
            $summary = $summaries[$index];
            $resolved = $heldStates[$summary->key()] ?? UserMediaState::untouched();

            $entries[] = [
                'id' => null,
                'at' => $state->updated_at?->toIso8601String(),
                'media' => $summary,
                'section' => MediaUserStatus::OnHold->value,
                'upNext' => $episodes[$summary->key()] ?? null,
                'watchedEpisodes' => $resolved->watchedEpisodes,
                'airedEpisodes' => $resolved->airedEpisodes,
                'remainingEpisodes' => $resolved->remainingEpisodes,
                'position' => $resolved->position,
                'nextEpisode' => $resolved->nextEpisode,
                'progress' => $resolved->progress,
                'remainingShare' => $this->totals->remainingShare($state->mediaItem, $resolved->progress),
                'skippedEpisodes' => $resolved->skippedEpisodes,
                'remainingMinutes' => $resolved->remainingMinutes,
                'watchedMinutes' => $resolved->watchedMinutes,
            ];
        }

        return $entries;
    }
}

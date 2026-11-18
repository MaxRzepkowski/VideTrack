<?php

namespace App\Users\Statistics;

use App\Accounts\UserCalendar;
use App\Catalog\MediaTypeRegistry;
use App\Enums\MediaUserStatus;
use App\Enums\PlayOutcome;
use App\Models\LibraryEntry;
use App\Models\MediaUserState;
use App\Models\User;
use App\Support\QueryRow;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use stdClass;

/**
 * What each title of a statistics period ended it as: finished, dropped, on
 * hold or still in progress — the set the page's marks, ratings and tiles
 * all count.
 */
final readonly class PeriodMarks
{
    public function __construct(private MediaTypeRegistry $types) {}

    /**
     * The period's titles, each with what it ended the period as. A title is
     * the period's when something of it counts in the period (see
     * UserHistory) or the user marked it then: finished it, dropped it, or
     * put it on hold. Parts roll up into their title, so a show counts once
     * however many episodes it had. The latest mark in the period wins; a
     * title without one is still in progress.
     *
     * A title is finished when its own entry was (a film, a game), or — one
     * watched part by part (a show) — when the library has it as watched
     * with its last part in the period, as the library stands today: a show
     * that got a new season since is no longer finished. A hold has no
     * history, only the open attempt's state, so a title is on hold in the
     * period when it is on hold today and was put there in it.
     *
     * @return array<int, array{type: string, mark: TitleMark}> root id => its type and mark
     */
    public function of(User $viewer, StatisticsScope $scope): array
    {
        /** @var array<int, array{type: string, mark: TitleMark, at: int}> $titles */
        $titles = [];

        foreach ($scope->counted as $entry) {
            $titles[$entry->rootId] ??= ['type' => $entry->rootType, 'mark' => TitleMark::InProgress, 'at' => PHP_INT_MIN];
        }

        $mark = function (int $rootId, string $type, TitleMark $mark, CarbonImmutable $at) use (&$titles): void {
            if ($at->getTimestamp() >= ($titles[$rootId]['at'] ?? PHP_INT_MIN)) {
                $titles[$rootId] = ['type' => $type, 'mark' => $mark, 'at' => $at->getTimestamp()];
            }
        };

        foreach ($scope->shown as $entry) {
            if ($entry->itemId === $entry->rootId) {
                $mark($entry->rootId, $entry->rootType, match ($entry->outcome) {
                    PlayOutcome::Completed => TitleMark::Completed,
                    PlayOutcome::Dropped => TitleMark::Dropped,
                }, $entry->at);
            }
        }

        $calendar = UserCalendar::of($viewer);

        foreach ($this->finishedContainers($viewer, $scope->type) as $rootId => [$type, $at]) {
            if ($scope->period->contains($calendar->moment($at)->toDateString())) {
                $mark($rootId, $type, TitleMark::Completed, $at);
            }
        }

        foreach ($this->holds($viewer, $scope->type) as $rootId => [$type, $at]) {
            if ($scope->period->contains($calendar->moment($at)->toDateString())) {
                $mark($rootId, $type, TitleMark::OnHold, $at);
            }
        }

        return array_map(fn (array $title): array => ['type' => $title['type'], 'mark' => $title['mark']], $titles);
    }

    /**
     * The title types to look at: the chosen one, or every type a title is
     * stored under, narrowed to those that can be `$of`.
     *
     * @param  list<string>  $of
     * @return list<string>
     */
    private function typesAmong(array $of, ?string $type): array
    {
        return $type === null ? $of : array_values(array_intersect($of, [$type]));
    }

    /**
     * Titles watched part by part that the library has as watched through,
     * with the moment their last part was finished.
     *
     * @return array<int, array{0: string, 1: CarbonImmutable}> root id => [type, when]
     */
    private function finishedContainers(User $viewer, ?string $type): array
    {
        $containers = $this->typesAmong($this->types->containerRootCodes(), $type);

        if ($containers === []) {
            return [];
        }

        $rows = LibraryEntry::query()
            ->finishedTitlesOf($viewer)
            ->whereNotNull('library_entries.watched_at')
            ->join('media_items', 'media_items.id', '=', 'library_entries.root_id')
            ->whereIn('media_items.type', $containers)
            ->selectRaw('library_entries.root_id as root_id, media_items.type as type, library_entries.watched_at as at')
            ->toBase()
            ->get();

        return $this->moments($rows);
    }

    /**
     * Titles on hold today, with the moment they were put there.
     *
     * @return array<int, array{0: string, 1: CarbonImmutable}> root id => [type, when]
     */
    private function holds(User $viewer, ?string $type): array
    {
        $rows = MediaUserState::query()
            ->where('media_user_states.user_id', $viewer->id)
            ->where('media_user_states.status', MediaUserStatus::OnHold)
            ->join('media_items', 'media_items.id', '=', 'media_user_states.media_item_id')
            ->whereIn('media_items.type', $this->typesAmong($this->types->titleCodes(), $type))
            ->selectRaw('media_user_states.media_item_id as root_id, media_items.type as type, media_user_states.updated_at as at')
            ->toBase()
            ->get();

        return $this->moments($rows);
    }

    /**
     * @param  Collection<int, stdClass>  $rows  with `root_id`, `type` and `at` (UTC)
     * @return array<int, array{0: string, 1: CarbonImmutable}>
     */
    private function moments(Collection $rows): array
    {
        $moments = [];

        foreach ($rows as $row) {
            $moments[QueryRow::int($row, 'root_id')] = [QueryRow::text($row, 'type'), CarbonImmutable::parse(QueryRow::text($row, 'at'), 'UTC')];
        }

        return $moments;
    }
}

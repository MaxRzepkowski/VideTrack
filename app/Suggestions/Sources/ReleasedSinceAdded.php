<?php

namespace App\Suggestions\Sources;

use App\Accounts\UserCalendar;
use App\Catalog\MediaItemSummaries;
use App\Enums\FollowMode;
use App\Models\Follow;
use App\Models\ListItem;
use App\Models\MediaItem;
use App\Models\User;
use App\Suggestions\AddedVia;
use App\Suggestions\Contracts\SuggestionSource;
use App\Suggestions\Data\Suggestion;
use App\Suggestions\Data\SuggestionGroup;
use App\Suggestions\SuggestionFilter;
use App\Support\QueryRow;
use Illuminate\Support\Carbon;

/**
 * Titles that got a bell or a place on a list while they were still
 * announcements, which have come out since. The latest premiere first.
 */
final readonly class ReleasedSinceAdded implements SuggestionSource
{
    public function __construct(private SuggestionFilter $filter) {}

    public function title(): string
    {
        return __('suggestions.released_since_added');
    }

    public function description(): string
    {
        return __('suggestions.released_since_added_rule');
    }

    public function origins(): array
    {
        $ways = [];

        foreach (AddedVia::cases() as $way) {
            $ways[$way->value] = $way->label();
        }

        return $ways;
    }

    public function wantedSets(User $user): array
    {
        return [];
    }

    public function groups(User $viewer, string $locale): array
    {
        $added = $this->added($viewer);

        if ($added === []) {
            return [];
        }

        $items = MediaItem::query()
            ->whereIn('id', array_keys($added))
            ->whereNull('parent_id')
            ->whereNull('gone_at')
            ->whereNotNull('release_date')
            ->orderByDesc('release_date')
            ->get();

        $cards = [];
        $origins = [];

        foreach ($items as $item) {
            // Only what was reached for while it was still to come: on an
            // earlier day than its release.
            $before = array_filter($added[$item->id], fn (string $day): bool => $item->release_date?->toDateString() > $day);

            if ($before !== []) {
                $cards[$item->id] = MediaItemSummaries::summary($item);
                $origins[$item->id] = $before;
            }
        }

        $standings = $this->filter->standings($viewer, array_values($cards));
        $suggestions = [];

        foreach ($cards as $id => $card) {
            if ($standings->isOpen($card)) {
                $suggestions[] = new Suggestion($card, [
                    __('suggestions.released_on', ['date' => $this->date((string) $card->releaseDate, $locale)]),
                    __('suggestions.added_on', ['date' => $this->date(min($origins[$id]), $locale)]),
                ], array_keys($origins[$id]), min($origins[$id]));
            }
        }

        return $suggestions === [] ? [] : [new SuggestionGroup($suggestions)];
    }

    /**
     * The day each title came to the user's attention, per way in: a bell
     * (their own, or the one a creator they follow rang for it) and a place
     * on one of their lists. A muted title is not waited for.
     *
     * @return array<int, array<string, string>> media item id => origin => earliest day
     */
    private function added(User $viewer): array
    {
        $bells = Follow::query()
            ->where('user_id', $viewer->id)
            ->where('mode', FollowMode::Follow->value)
            ->whereNotNull('media_item_id')
            ->toBase()
            ->get(['media_item_id', 'created_at']);

        $listed = ListItem::query()
            ->whereNotNull('media_item_id')
            ->whereHas('list', fn ($list) => $list->where('user_id', $viewer->id))
            ->toBase()
            ->get(['media_item_id', 'created_at']);

        // The day on the viewer's calendar, to set against a release day.
        $calendar = UserCalendar::of($viewer);
        $added = [];

        foreach ([AddedVia::Followed->value => $bells, AddedVia::Listed->value => $listed] as $origin => $rows) {
            foreach ($rows as $row) {
                $createdAt = QueryRow::textOrNull($row, 'created_at');

                if ($createdAt === null) {
                    continue;
                }

                $id = QueryRow::int($row, 'media_item_id');
                $day = $calendar->dayOfStored($createdAt);
                $added[$id][$origin] = min($added[$id][$origin] ?? $day, $day);
            }
        }

        return $added;
    }

    /** Localized date with a short month, so the two of them fit under a poster. */
    private function date(string $day, string $locale): string
    {
        return Carbon::parse($day)->settings(['locale' => $locale])->isoFormat('ll');
    }
}

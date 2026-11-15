<?php

namespace App\Integrations\Export;

use App\Accounts\UserCalendar;
use App\Catalog\ItemMetadata;
use App\Enums\PlayOutcome;
use App\Models\MediaItem;
use App\Models\Play;
use App\Models\Rating;
use App\Models\User;
use DateTimeInterface;
use Generator;
use Illuminate\Database\Eloquent\Builder;

/**
 * What a user rated or watched to the end, one record per title of the given types,
 * by title and year — the shape the exports to rating services outside
 * VideTrack (Letterboxd, IMDb) are made of. Each export says which types its
 * service knows; everything else (games, books, episodes) has no place to
 * land there and stays out.
 */
final readonly class TitleRecords
{
    private const CHUNK = 500;

    /**
     * @param  list<string>  $types
     * @return Generator<int, TitleRecord>
     */
    public function of(User $user, array $types): Generator
    {
        $ratings = Rating::query()
            ->where('user_id', $user->id)
            ->get(['media_item_id', 'rating', 'updated_at'])
            ->keyBy('media_item_id');

        // A drop is an attempt, not a viewing: the services read a date as "watched".
        $watched = Play::query()
            ->where('user_id', $user->id)
            ->where('outcome', PlayOutcome::Completed->value)
            ->selectRaw('media_item_id, MAX(watched_at) as watched_at')
            ->groupBy('media_item_id')
            ->pluck('watched_at', 'media_item_id');

        $items = MediaItem::query()
            ->whereIn('type', $types)
            ->where(fn (Builder $query) => $query
                ->whereHas('ratings', fn (Builder $r) => $r->where('user_id', $user->id))
                ->orWhereHas('plays', fn (Builder $p) => $p->where('user_id', $user->id)->where('outcome', PlayOutcome::Completed->value)))
            ->orderBy('title')
            ->orderBy('year')
            ->orderBy('id')
            ->lazy(self::CHUNK);

        $calendar = UserCalendar::of($user);

        foreach ($items as $item) {
            /** @var MediaItem $item */
            $rating = $ratings->get($item->id);

            yield new TitleRecord(
                $item->type,
                $item->title,
                $item->year,
                $item->provider,
                $item->provider_ref,
                ItemMetadata::fromStored($item->metadata)->imdbId(),
                $rating?->rating,
                $calendar->day($rating?->updated_at),
                ($watchedAt = $watched->get($item->id)) instanceof DateTimeInterface ? $calendar->day($watchedAt) : null,
            );
        }
    }
}

<?php

namespace App\Integrations\Export;

use App\Catalog\MediaKind;
use App\Models\LibraryEntry;
use App\Models\ListItem;
use App\Models\MediaItem;
use App\Models\MediaList;
use App\Models\MediaUserState;
use App\Models\Play;
use App\Models\Rating;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Where a user stands with each title of one type, for the exports whose
 * service keeps a list of titles in states (Goodreads' shelves,
 * MyAnimeList's statuses): every title the user has a word about, with
 * what the library keeps of it. Which state that makes is each service's
 * own call. One query per kind of fact, whatever the library's size.
 */
final readonly class ExportStandings
{
    /**
     * History, a rating, an open attempt or a place on one of the user's
     * lists, in title order.
     *
     * @return list<TitleStanding>
     */
    public function of(User $user, MediaKind $type): array
    {
        $lists = MediaList::query()->select('id')->where('user_id', $user->id);
        $items = MediaItem::query()
            ->where('type', $type->value)
            ->where(fn (Builder $query) => $query
                ->whereIn('id', LibraryEntry::query()->ofRoots()->select('media_item_id')->where('user_id', $user->id))
                ->orWhereIn('id', Rating::query()->select('media_item_id')->where('user_id', $user->id))
                ->orWhereIn('id', MediaUserState::query()->select('media_item_id')->where('user_id', $user->id))
                ->orWhereIn('id', ListItem::query()->select('media_item_id')->whereIn('list_id', $lists)))
            ->orderBy('title')
            ->orderBy('id')
            ->get();
        $ids = $items->modelKeys();

        $entries = LibraryEntry::query()->ofRoots()->where('user_id', $user->id)->whereIn('media_item_id', $ids)->get()->keyBy('media_item_id');
        $ratings = Rating::query()->where('user_id', $user->id)->whereIn('media_item_id', $ids)->get()->keyBy('media_item_id');
        $opened = MediaUserState::query()->where('user_id', $user->id)->whereIn('media_item_id', $ids)->get()->keyBy('media_item_id');
        $plays = Play::query()->where('user_id', $user->id)->whereIn('media_item_id', $ids)->orderBy('watched_at')->orderBy('id')->get()->groupBy('media_item_id');
        $memberships = ListItem::query()->with('list')->whereIn('list_id', $lists)->whereIn('media_item_id', $ids)->get()->groupBy('media_item_id');
        $standings = [];

        foreach ($items as $item) {
            $standings[] = new TitleStanding(
                $item,
                $entries->get($item->id),
                $ratings->get($item->id),
                $opened->get($item->id),
                $plays->get($item->id) ?? new Collection,
                $memberships->get($item->id) ?? new Collection,
            );
        }

        return $standings;
    }
}

<?php

namespace App\Actions\Media;

use App\Catalog\Data\MediaSummary;
use App\Catalog\MediaItemSummaries;
use App\Catalog\ReaderTitleSql;
use App\Library\LibraryHolders;
use App\Models\MediaItem;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\App;

/**
 * Title search over what the user already tracks — the command palette's index.
 * Deliberately NOT a provider search: the palette
 * is "jump to something of mine", answered from SQLite in one query, so it
 * responds on the first letter instead of waiting on a network round trip.
 * Finding something new stays the search page's job.
 *
 * "Mine" is any link between the user and a persisted item, as
 * {@see LibraryHolders} counts them, climbed to the title it belongs to —
 * watching a show writes plays on its episodes, not on the show. `media_items` rows are
 * shared by the whole instance, so that scoping is also what keeps one
 * account's titles out of another's palette.
 */
final readonly class SearchLibrary
{
    public function __construct(private LibraryHolders $holders) {}

    /**
     * @return list<MediaSummary>
     */
    public function execute(User $user, string $query, int $limit): array
    {
        $term = trim($query);

        if ($term === '') {
            return [];
        }

        $pattern = $this->escapeLike($term);
        $locale = App::getLocale();

        return array_values(MediaItem::query()
            ->whereIn('id', $this->holders->rootsHeldBy($user->id))
            // The name on the reader's card, or the row's own: a title is
            // found by either of the names it is known by.
            ->where(fn (Builder $where) => $where
                ->whereRaw(new ReaderTitleSql($locale, "%s like ? escape '\\'"), ['%'.$pattern.'%'])
                ->orWhereRaw("title like ? escape '\\'", ['%'.$pattern.'%']))
            // A prefix match is what was meant far more often than a hit in the
            // middle of a title ("matrix" → "The Matrix" before "Dual Matrix").
            // orderBy() takes no bindings, so the prefix joins the order's own.
            ->orderBy(new ReaderTitleSql($locale, "case when %s like ? escape '\\' then 0 else 1 end"))
            ->addBinding($pattern.'%', 'order')
            ->orderBy(new ReaderTitleSql($locale))
            ->limit($limit)
            ->get()
            ->map(fn (MediaItem $item): MediaSummary => MediaItemSummaries::summary($item))
            ->all());
    }

    /**
     * A typed `%` or `_` is a literal here, not a wildcard — a search for
     * "100%" must not match every title. Paired with an explicit `escape`
     * clause, because neither SQLite nor Laravel assumes one.
     */
    private function escapeLike(string $term): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $term);
    }
}

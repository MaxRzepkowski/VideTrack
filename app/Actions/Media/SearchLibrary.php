<?php

namespace App\Actions\Media;

use App\Catalog\Data\MediaSummary;
use App\Catalog\MediaItemSummaries;
use App\Library\LibraryHolders;
use App\Models\MediaItem;
use App\Models\User;
use App\Support\Locales;
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
        $items = MediaItem::query();
        $name = $this->readerTitle($items, App::getLocale());

        return array_values($items
            ->whereIn('id', $this->holders->rootsHeldBy($user->id))
            // The name on the reader's card, or the row's own: a title is
            // found by either of the names it is known by.
            ->where(fn (Builder $where) => $where
                ->whereRaw("{$name} like ? escape '\\'", ['%'.$pattern.'%'])
                ->orWhereRaw("title like ? escape '\\'", ['%'.$pattern.'%']))
            // A prefix match is what was meant far more often than a hit in the
            // middle of a title ("matrix" → "The Matrix" before "Dual Matrix").
            ->orderByRaw("case when {$name} like ? escape '\\' then 0 else 1 end", [$pattern.'%'])
            ->orderByRaw($name)
            ->limit($limit)
            ->get()
            ->map(fn (MediaItem $item): MediaSummary => MediaItemSummaries::summary($item))
            ->all());
    }

    /**
     * The SQL for the name a reader of `$locale` sees — the walk of
     * MediaItem::titleIn(): their language, the fallback one, the row's own.
     *
     * @param  Builder<MediaItem>  $items
     */
    private function readerTitle(Builder $items, string $locale): string
    {
        $grammar = $items->getQuery()->getGrammar();

        return sprintf(
            'coalesce(%s, %s, title)',
            $grammar->wrap("localized_titles->{$locale}->title"),
            $grammar->wrap('localized_titles->'.Locales::fallback().'->title'),
        );
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

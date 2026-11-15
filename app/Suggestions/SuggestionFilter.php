<?php

namespace App\Suggestions;

use App\Catalog\Data\MediaSummary;
use App\Models\LibraryEntry;
use App\Models\User;
use App\Suggestions\Data\TitleStandings;
use App\Support\QueryRow;

/**
 * The one rule for what a shelf may not put forward: a title not out yet, and
 * a title the user has already finished, begun or dropped. Read from the
 * library projection, like every card's state.
 */
final readonly class SuggestionFilter
{
    /** Titles whose standing is read in one query. */
    private const CHUNK = 500;

    /**
     * @param  list<MediaSummary>  $titles
     */
    public function standings(User $viewer, array $titles): TitleStandings
    {
        $refsByType = [];

        foreach ($titles as $title) {
            $refsByType[$title->type][] = $title->ref;
        }

        $finished = [];
        $touched = [];

        foreach ($refsByType as $type => $refs) {
            foreach (array_chunk(array_values(array_unique($refs)), self::CHUNK) as $chunk) {
                $entries = LibraryEntry::query()
                    ->ofRoots()
                    ->where('library_entries.user_id', $viewer->id)
                    ->join('media_items', 'media_items.id', '=', 'library_entries.media_item_id')
                    ->where('media_items.type', $type)
                    ->whereIn('media_items.provider_ref', $chunk)
                    ->toBase()
                    ->get(['media_items.provider_ref', 'library_entries.watched', 'library_entries.played', 'library_entries.dropped_at']);

                foreach ($entries as $entry) {
                    $key = MediaSummary::keyOf($type, QueryRow::text($entry, 'provider_ref'));

                    if ((bool) $entry->watched) {
                        $finished[$key] = true;
                    } elseif ((bool) $entry->played || $entry->dropped_at !== null) {
                        $touched[$key] = true;
                    }
                }
            }
        }

        return new TitleStandings($finished, $touched, today());
    }
}

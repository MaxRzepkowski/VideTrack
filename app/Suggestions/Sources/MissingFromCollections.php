<?php

namespace App\Suggestions\Sources;

use App\Catalog\CardSnapshot;
use App\Catalog\ItemMetadata;
use App\Catalog\Sets\SetKey;
use App\Catalog\Sets\SetKind;
use App\Catalog\Sets\SetStore;
use App\Models\LibraryEntry;
use App\Models\MediaItem;
use App\Models\User;
use App\Suggestions\Contracts\SuggestionSource;
use App\Suggestions\Data\Suggestion;
use App\Suggestions\Data\SuggestionGroup;
use App\Suggestions\SuggestionFilter;

/**
 * The parts still unseen of every collection the user has finished a part
 * of. Collections closest to complete come first.
 */
final readonly class MissingFromCollections implements SuggestionSource
{
    public function __construct(
        private SetStore $sets,
        private SuggestionFilter $filter,
    ) {}

    public function title(): string
    {
        return __('suggestions.missing_from_collections');
    }

    public function description(): string
    {
        return __('suggestions.missing_from_collections_rule');
    }

    public function origins(): array
    {
        return [];
    }

    public function wantedSets(User $user): array
    {
        $keys = [];

        $films = ItemMetadata::having(MediaItem::query(), 'collection')
            ->whereIn('id', LibraryEntry::query()->finishedTitlesOf($user)->select('media_item_id'))
            ->get(['id', 'provider', 'metadata']);

        foreach ($films as $film) {
            $collection = ItemMetadata::fromStored($film->metadata)->collection;

            if ($collection !== null) {
                $key = new SetKey(SetKind::Collection, $film->provider, $collection->ref);
                $keys[$key->id()] = $key;
            }
        }

        return array_values($keys);
    }

    public function groups(User $viewer, string $locale): array
    {
        $sets = $this->sets->read($this->wantedSets($viewer), $locale);

        $parts = [];

        foreach ($sets as $set) {
            foreach ($set->titles as $title) {
                $parts[] = CardSnapshot::of($title);
            }
        }

        $standings = $this->filter->standings($viewer, $parts);
        $ranked = [];

        foreach ($sets as $set) {
            $out = array_values(array_filter(
                array_map(CardSnapshot::of(...), $set->titles->all()),
                $standings->isOut(...),
            ));
            $finished = count(array_filter($out, $standings->hasFinished(...)));
            // The collection list keeps a part the provider has no poster for
            // yet; the shelf does not put it forward.
            $missing = array_values(array_filter($out, $standings->isSuggestible(...)));

            $name = $set->name;

            if ($name === null || $finished === 0 || $missing === []) {
                continue;
            }

            $caption = __('suggestions.watched_of_parts', ['watched' => $finished, 'total' => count($out)]);

            $ranked[] = [
                'missing' => count($missing),
                'finished' => $finished,
                'group' => new SuggestionGroup(
                    suggestions: array_map(fn ($part) => new Suggestion($part, [$name, $caption]), $missing),
                    key: "{$set->provider}-{$set->ref}",
                    title: $name,
                    href: route('collections.show', ['provider' => $set->provider, 'ref' => $set->ref], false),
                    caption: $caption,
                    posterPath: $set->poster_path,
                    finished: $finished,
                    total: count($out),
                ),
            ];
        }

        // Fewest parts left first; among those, the collection the user is deepest into.
        usort($ranked, fn (array $a, array $b): int => [$a['missing'], $b['finished']] <=> [$b['missing'], $a['finished']]);

        return array_column($ranked, 'group');
    }
}

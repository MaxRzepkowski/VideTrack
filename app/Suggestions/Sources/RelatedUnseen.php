<?php

namespace App\Suggestions\Sources;

use App\Catalog\Relations\RelationStore;
use App\Catalog\Relations\TitleRelation;
use App\Models\LibraryEntry;
use App\Models\MediaItem;
use App\Models\User;
use App\Suggestions\Contracts\SuggestionSource;
use App\Suggestions\Data\Suggestion;
use App\Suggestions\Data\SuggestionGroup;
use App\Suggestions\SuggestionFilter;

/**
 * The spin-offs of what the user has finished, and what those titles were
 * themselves spun off from, that the user has not seen. Continuations are
 * left to the collections shelf, which already lists a story's next part.
 * Read from the ties the background task keeps; the latest release first.
 */
final readonly class RelatedUnseen implements SuggestionSource
{
    /** The ties this shelf follows. */
    private const FOLLOWED = [TitleRelation::SpinOff, TitleRelation::SpinOffOf];

    public function __construct(
        private RelationStore $relations,
        private SuggestionFilter $filter,
    ) {}

    public function title(): string
    {
        return __('suggestions.related_unseen');
    }

    public function description(): string
    {
        return __('suggestions.related_unseen_rule');
    }

    public function origins(): array
    {
        $ways = [];

        foreach (self::FOLLOWED as $relation) {
            $ways[$relation->value] = (string) $relation->label();
        }

        return $ways;
    }

    public function wantedSets(User $user): array
    {
        return [];
    }

    public function groups(User $viewer, string $locale): array
    {
        $finished = MediaItem::query()
            ->whereIn('id', LibraryEntry::query()->finishedTitlesOf($viewer)->select('media_item_id'))
            ->get(['id', 'type', 'provider', 'provider_ref', 'title', 'localized_titles', 'release_date'])
            ->keyBy('id');

        $found = [];

        foreach ($this->relations->ofMany($finished) as $sourceId => $ties) {
            $source = $finished->get($sourceId);

            if ($source === null) {
                continue;
            }

            foreach ($ties as $tie) {
                if (! in_array($tie->relation, self::FOLLOWED, true)) {
                    continue;
                }

                $card = $tie->media;
                // Reached from several finished titles: one card, every way in.
                $found[$card->key()] ??= ['card' => $card, 'reason' => $this->reason($tie->relation, $source->titleIn($locale)), 'origins' => []];
                $found[$card->key()]['origins'][$tie->relation->value] = true;
            }
        }

        $standings = $this->filter->standings($viewer, array_column($found, 'card'));
        $suggestions = [];

        foreach ($found as $entry) {
            if ($standings->isOpen($entry['card'])) {
                $suggestions[] = new Suggestion($entry['card'], [$entry['reason']], array_keys($entry['origins']));
            }
        }

        usort($suggestions, fn (Suggestion $a, Suggestion $b): int => [$b->media->releaseDate, $a->media->title] <=> [$a->media->releaseDate, $b->media->title]);

        return $suggestions === [] ? [] : [new SuggestionGroup($suggestions)];
    }

    /** How the suggested title stands to the finished one, as one sentence naming it. */
    private function reason(TitleRelation $relation, string $title): string
    {
        return $relation === TitleRelation::SpinOffOf
            ? __('suggestions.origin_of_title', ['title' => $title])
            : __('suggestions.spin_off_of_title', ['title' => $title]);
    }
}

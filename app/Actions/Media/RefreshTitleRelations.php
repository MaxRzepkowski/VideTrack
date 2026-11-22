<?php

namespace App\Actions\Media;

use App\Catalog\MediaKind;
use App\Catalog\Relations\RelatedWork;
use App\Catalog\Relations\RelationStore;
use App\Catalog\Relations\WorkFinder;
use App\Catalog\Wikidata\CatalogueId;
use App\Catalog\Wikidata\RelatedWorks;
use App\Catalog\Wikidata\TitleItems;
use App\Models\MediaItem;
use App\Support\ExternalServiceException;
use Illuminate\Database\Eloquent\Collection;

/**
 * Asks the outside source what a batch of stored titles is tied to: a film's
 * or a show's spin-offs, continuations and franchises, and any title's works
 * in other media (what it is based on, what was made of it, what shares its
 * source). Finds each tied work in our catalogue and keeps the ties as the
 * source gave them: what of them a page shows is TieRules' to say, so the
 * rules can change without asking again. A work is left out when our
 * catalogue does not have it, its provider files it as adult material, or it
 * is of the title's own type across media (a remake, a port), which is no
 * other medium and would cost a lookup for every one. The franchises found
 * are listed by ListFranchiseTitles.
 */
final readonly class RefreshTitleRelations
{
    public function __construct(
        private TitleItems $items,
        private RelatedWorks $relatedWorks,
        private RelationStore $store,
        private WorkFinder $works,
    ) {}

    /**
     * @param  Collection<int, MediaItem>  $titles  roots the source can find
     * @return int ties kept
     *
     * @throws ExternalServiceException the source or a provider did not answer; titles already written stay written
     */
    public function execute(Collection $titles, string $locale): int
    {
        $itemsOf = $this->items->of($titles);
        $titleOf = [];
        $onScreen = [];

        foreach ($titles as $title) {
            foreach ($itemsOf[$title->id] ?? [] as $item) {
                $titleOf[$item] = $title;

                if (CatalogueId::isScreen(MediaKind::from($title->type))) {
                    $onScreen[] = $item;
                }
            }
        }

        $answer = $this->relatedWorks->of($onScreen);
        $acrossMedia = $this->relatedWorks->acrossMedia(array_keys($titleOf));

        /** @var array<int, list<RelatedWork>> $tiesOf */
        $tiesOf = [];
        /** @var array<int, list<string>> $franchisesOf */
        $franchisesOf = [];

        foreach ($titleOf as $item => $title) {
            $across = array_filter($acrossMedia[$item] ?? [], fn (RelatedWork $tie): bool => $tie->work->kind->value !== $title->type);
            $tiesOf[$title->id] = [...$tiesOf[$title->id] ?? [], ...$answer->ties[$item] ?? [], ...array_values($across)];
            $franchisesOf[$title->id] = [...$franchisesOf[$title->id] ?? [], ...$answer->franchises[$item] ?? []];
        }

        // Every tied work of the batch is found in one pass, not one by one.
        $works = [];

        foreach ($tiesOf as $titleId => $ofTitle) {
            foreach ($ofTitle as $index => $tie) {
                $works["{$titleId}:{$index}"] = $tie->work;
            }
        }

        $found = $this->works->findAll($works, $locale);
        $kept = 0;

        foreach ($titles as $title) {
            $ties = [];

            foreach ($tiesOf[$title->id] ?? [] as $index => $tie) {
                $card = $found["{$title->id}:{$index}"] ?? null;

                // The source lists a title among its own ties now and then.
                if ($card !== null && ! ($card['card']->type === $title->type && $card['card']->ref === $title->provider_ref)) {
                    $ties[] = [$tie->relation, $card['card'], $card['votes']];
                }
            }

            $this->store->replace($title, $ties);
            $this->store->linkFranchises($title, $franchisesOf[$title->id] ?? []);
            $kept += count($ties);
        }

        return $kept;
    }
}

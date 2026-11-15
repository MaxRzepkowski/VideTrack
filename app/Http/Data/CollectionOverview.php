<?php

namespace App\Http\Data;

use App\Catalog\Data\CreatorLink;
use App\Catalog\Data\KeywordData;
use JsonSerializable;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * What a collection's page says of the saga as a whole, summed and gathered
 * from its parts. Every list runs from what most parts share to what fewest do.
 */
#[TypeScript]
final readonly class CollectionOverview implements JsonSerializable
{
    /**
     * @param  int|null  $budget  the sum over the `$budgetCounted` parts that have one
     * @param  int|null  $revenue  the sum over the `$revenueCounted` parts that have one
     * @param  int|null  $totalRuntime  minutes, over the parts with a runtime
     * @param  list<CreatorLink>  $studios
     * @param  list<string>  $productionCountries  ISO 3166-1 codes
     * @param  list<string>  $originalLanguages  ISO 639-1 codes
     * @param  list<string>  $certifications  every age rating the parts carry
     * @param  list<KeywordData>  $keywords
     * @param  list<CollectionCredit>  $cast
     * @param  list<CollectionCredit>  $crew
     */
    public function __construct(
        public int $titleCount,
        public int $releasedCount,
        public int $upcomingCount,
        public ?int $budget,
        public int $budgetCounted,
        public ?int $revenue,
        public int $revenueCounted,
        public ?int $totalRuntime,
        public array $studios,
        public array $productionCountries,
        public array $originalLanguages,
        public array $certifications,
        public array $keywords,
        public array $cast,
        public array $crew,
    ) {}

    /**
     * @return array{
     *     titleCount: int,
     *     releasedCount: int,
     *     upcomingCount: int,
     *     budget: int|null,
     *     budgetCounted: int,
     *     revenue: int|null,
     *     revenueCounted: int,
     *     totalRuntime: int|null,
     *     studios: list<CreatorLink>,
     *     productionCountries: list<string>,
     *     originalLanguages: list<string>,
     *     certifications: list<string>,
     *     keywords: list<KeywordData>,
     *     cast: list<CollectionCredit>,
     *     crew: list<CollectionCredit>
     * }
     */
    public function jsonSerialize(): array
    {
        return [
            'titleCount' => $this->titleCount,
            'releasedCount' => $this->releasedCount,
            'upcomingCount' => $this->upcomingCount,
            'budget' => $this->budget,
            'budgetCounted' => $this->budgetCounted,
            'revenue' => $this->revenue,
            'revenueCounted' => $this->revenueCounted,
            'totalRuntime' => $this->totalRuntime,
            'studios' => $this->studios,
            'productionCountries' => $this->productionCountries,
            'originalLanguages' => $this->originalLanguages,
            'certifications' => $this->certifications,
            'keywords' => $this->keywords,
            'cast' => $this->cast,
            'crew' => $this->crew,
        ];
    }
}

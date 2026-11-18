<?php

namespace App\Http\Data;

use App\Catalog\Creators\CreatorKind;
use App\Users\Statistics\Data\MakerTally;
use App\Users\Statistics\Data\OriginTally;
use App\Users\Statistics\Data\RatedTitle;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** Where a period's titles come from: their countries and original languages, and the networks or studios behind them. */
#[TypeScript]
final readonly class StatisticsOrigins
{
    /**
     * @param  list<OriginTally>  $countries  most titles first
     * @param  list<string>  $countryTypes  the types whose titles name a country, in the registry's order
     * @param  list<OriginTally>  $languages  most titles first
     * @param  list<string>  $languageTypes  the types whose titles name a language, in the registry's order
     * @param  list<MakerTally>  $makers  most titles first
     * @param  list<RatedTitle>  $titles  the makers' best titles, each once; a maker's `best` points into it
     */
    public function __construct(
        public array $countries,
        public array $countryTypes,
        public array $languages,
        public array $languageTypes,
        public array $makers,
        /** Networks when any title names one, studios otherwise; null when no title names either. */
        public ?CreatorKind $makerKind,
        public array $titles,
    ) {}
}

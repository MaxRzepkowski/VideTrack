<?php

namespace App\Catalog\Data;

use JsonSerializable;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One chunk of a creator's catalogue, plus whether the provider still holds
 * more behind it.
 *
 * Company and network pages stream: a studio like Warner Bros has thousands of
 * titles and TMDB hands out twenty per request, so the page opens with the
 * first chunk and asks for the next ones as the user goes deeper. Person and
 * author pages need none of this — their providers serve a whole filmography
 * (or shelf) at once.
 */
#[TypeScript]
final readonly class CreditPage implements JsonSerializable
{
    /**
     * @param  list<CompanyCredit>  $credits
     */
    public function __construct(
        public array $credits,
        public bool $hasMore,
    ) {}

    /**
     * @return array{
     *     credits: list<CompanyCredit>,
     *     hasMore: bool
     * }
     */
    public function jsonSerialize(): array
    {
        return [
            'credits' => $this->credits,
            'hasMore' => $this->hasMore,
        ];
    }
}

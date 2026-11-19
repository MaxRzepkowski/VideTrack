<?php

namespace App\Http\Resources\Api\V1;

use App\Catalog\Data\BrowseSections;
use App\Library\Data\UserMediaState;
use Illuminate\Http\Request;

/**
 * The discover page's sections of one type, each card with the user's state:
 * trending, popular and upcoming at the type's provider.
 *
 * @mixin BrowseSections
 */
class DiscoverSectionsResource extends ApiResource
{
    /**
     * @param  array<string, UserMediaState>  $states  the library's "type:ref" map
     */
    public function __construct(private readonly BrowseSections $sections, private readonly array $states)
    {
        parent::__construct($sections);
    }

    /**
     * @return array{trending: array<array-key, mixed>, popular: array<array-key, mixed>, upcoming: array<array-key, mixed>}
     */
    public function toArray(Request $request): array
    {
        return [
            'trending' => MediaSummaryResource::withStates($this->sections->trending, $this->states)->resolve($request),
            'popular' => MediaSummaryResource::withStates($this->sections->popular, $this->states)->resolve($request),
            'upcoming' => MediaSummaryResource::withStates($this->sections->upcoming, $this->states)->resolve($request),
        ];
    }
}

<?php

namespace App\Http\Resources\Api\V1;

use App\Catalog\Data\TitleLookup;
use App\Library\Data\UserMediaState;
use Illuminate\Http\Request;

/**
 * One title asked for by name: what was asked, and the card found under it
 * with the user's state, or none.
 *
 * @mixin TitleLookup
 */
class TitleLookupResource extends ApiResource
{
    public function __construct(private readonly TitleLookup $lookup, private readonly ?UserMediaState $state = null)
    {
        parent::__construct($lookup);
    }

    /**
     * @return array{title: string, type: string, year: int|null, media: array<array-key, mixed>|null, unavailable: bool}
     */
    public function toArray(Request $request): array
    {
        return [
            'title' => $this->lookup->title,
            'type' => $this->lookup->kind->value,
            'year' => $this->lookup->year,
            'media' => $this->lookup->match === null ? null : (new MediaSummaryResource($this->lookup->match, $this->state))->resolve($request),
            'unavailable' => $this->lookup->unavailable,
        ];
    }
}

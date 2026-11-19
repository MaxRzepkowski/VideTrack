<?php

namespace App\Http\Resources\Api\V1;

use App\Library\Data\UpNextEntry;
use Illuminate\Http\Request;

/**
 * One show of the "Up next" shelf: the show, the first episode the user has
 * not watched, and how far along the show they are. The bar's segments and
 * the card's poster stay with the page.
 *
 * @mixin UpNextEntry
 */
class UpNextResource extends ApiResource
{
    public function __construct(private readonly UpNextEntry $entry)
    {
        parent::__construct($entry);
    }

    /**
     * @return array{show: array<array-key, mixed>, episode: array<array-key, mixed>, watchedEpisodes: int, airedEpisodes: int, remainingEpisodes: int}
     */
    public function toArray(Request $request): array
    {
        return [
            'show' => (new MediaSummaryResource($this->entry->show))->resolve($request),
            'episode' => (new MediaSummaryResource($this->entry->episode))->resolve($request),
            'watchedEpisodes' => $this->entry->watchedEpisodes,
            'airedEpisodes' => $this->entry->airedEpisodes,
            'remainingEpisodes' => $this->entry->remainingEpisodes,
        ];
    }
}

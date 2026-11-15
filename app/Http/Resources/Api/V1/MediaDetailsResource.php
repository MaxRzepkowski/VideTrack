<?php

namespace App\Http\Resources\Api\V1;

use App\Catalog\Data\KeywordData;
use App\Catalog\Data\MediaDetails;
use App\Library\Data\UserMediaState;
use Illuminate\Http\Request;

/**
 * The full detail payload, minus what only a rendered page needs (images
 * beyond the poster, videos, recommendations), with the user's state on the
 * position. Type-specific fields stay in `extra`, exactly as the provider
 * layer delivers them — the core does not know what a game's or a book's
 * extras mean.
 *
 * @mixin MediaDetails
 */
class MediaDetailsResource extends ApiResource
{
    public function __construct(MediaDetails $details, private readonly UserMediaState $state)
    {
        parent::__construct($details);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            ...(new MediaSummaryResource($this->summary))->toArray($request),
            'tagline' => $this->tagline,
            'overview' => $this->overview,
            'runtime' => $this->runtime,
            'genres' => $this->genres,
            'status' => $this->status?->value,
            'originalLanguage' => $this->originalLanguage,
            'certification' => $this->certification,
            'keywords' => array_map(fn (KeywordData $keyword): string => $keyword->name, $this->keywords),
            'externalLinks' => $this->externalLinks,
            'extra' => $this->wireExtra(),
            'children' => MediaSummaryResource::collection($this->children),
            'state' => $this->state,
        ];
    }
}

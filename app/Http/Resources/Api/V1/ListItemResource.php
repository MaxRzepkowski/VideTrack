<?php

namespace App\Http\Resources\Api\V1;

use App\Catalog\Data\MediaSummary;
use App\Catalog\ImageUrls;
use App\Models\ListItem;
use Illuminate\Http\Request;

/**
 * One position on a list. Exactly one of `media`, `person`, `company` is
 * filled — people and companies go on custom lists alone.
 *
 * @mixin ListItem
 */
class ListItemResource extends ApiResource
{
    /**
     * @param  MediaSummary|null  $card  the title's card in its hierarchy, for a title position
     */
    public function __construct(ListItem $item, private readonly ?MediaSummary $card = null)
    {
        parent::__construct($item);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'position' => $this->position,
            'addedAt' => $this->created_at?->toIso8601String(),
            'media' => $this->card === null ? null : new MediaSummaryResource($this->card),
            'person' => $this->person === null ? null : [
                'provider' => $this->person->provider,
                'ref' => $this->person->provider_ref,
                'name' => $this->person->name,
                'profilePath' => $this->person->profile_path,
                'profileUrl' => ImageUrls::of($this->person->profile_path, ImageUrls::POSTER),
            ],
            'company' => $this->company === null ? null : [
                'provider' => $this->company->provider,
                'ref' => $this->company->provider_ref,
                'kind' => $this->company->kind,
                'name' => $this->company->name,
                'logoPath' => $this->company->logo_path,
                'logoUrl' => ImageUrls::of($this->company->logo_path, ImageUrls::BACKDROP),
            ],
        ];
    }
}

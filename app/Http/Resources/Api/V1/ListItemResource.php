<?php

namespace App\Http\Resources\Api\V1;

use App\Catalog\Data\MediaSummary;
use App\Catalog\PublicShapes;
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
            'person' => $this->person === null ? null : PublicShapes::person($this->person),
            'company' => $this->company === null ? null : PublicShapes::company($this->company),
        ];
    }
}

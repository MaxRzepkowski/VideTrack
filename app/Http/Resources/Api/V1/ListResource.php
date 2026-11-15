<?php

namespace App\Http\Resources\Api\V1;

use App\Catalog\MediaItemSummaries;
use App\Models\ListItem;
use App\Models\MediaList;
use Illuminate\Http\Request;
use LogicException;

/**
 * One of the user's lists. `systemKey` names the lists VideTrack maintains
 * itself (the watchlist, materialized provider collections); a null key is
 * a list the user made.
 *
 * @mixin MediaList
 */
class ListResource extends ApiResource
{
    /** Builds the cards of a list served with its items. */
    private ?MediaItemSummaries $summaries = null;

    /**
     * The list with its items (loaded with `mediaItem.parent.parent`): a
     * position's card names its show and season, as on the list's page. Not a
     * constructor argument: a resource collection hands its key in that place.
     */
    public static function withCards(MediaList $list, MediaItemSummaries $summaries): self
    {
        $resource = new self($list);
        $resource->summaries = $summaries;

        return $resource;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            // The watchlist stores no title; a client gets the name the app shows.
            'title' => $this->displayTitle(),
            'description' => $this->description,
            'isPublic' => (bool) $this->is_public,
            'systemKey' => $this->system_key,
            'itemsCount' => $this->items_count,
            'createdAt' => $this->created_at?->toIso8601String(),
            'items' => new ApiCollection(
                $this->whenLoaded('items', fn () => $this->items->map(
                    fn (ListItem $item): ListItemResource => new ListItemResource(
                        $item,
                        $item->mediaItem === null ? null : ($this->summaries ?? throw new LogicException('A list served with its items needs the cards in context.'))->inContext($item->mediaItem),
                    ),
                )),
                ListItemResource::class,
            ),
        ];
    }
}

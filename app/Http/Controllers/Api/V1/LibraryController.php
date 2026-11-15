<?php

namespace App\Http\Controllers\Api\V1;

use App\Catalog\MediaItemSummaries;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\LibraryRequest;
use App\Http\Resources\Api\V1\MediaSummaryResource;
use App\Library\LibraryStates;
use App\Library\LibraryTabQuery;
use App\Models\MediaItem;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Collection;

/**
 * Everything the user has in their library, one tab at a time: history,
 * ratings, favorites, open attempts. Deliberately simpler than the profile
 * panels — those carry the page's grouping, filters and day totals; a client
 * wants a plain, stable page of positions it can walk.
 */
class LibraryController extends Controller
{
    /**
     * @return AnonymousResourceCollection<int, MediaSummaryResource>
     */
    public function __invoke(#[CurrentUser] User $viewer, LibraryRequest $request, LibraryStates $states, MediaItemSummaries $cards, LibraryTabQuery $tabs): AnonymousResourceCollection
    {
        $page = $tabs->of($viewer, $request->tab(), $request->mediaType())
            ->paginate($request->perPage())
            ->withQueryString();

        /** @var Collection<int, MediaItem> $items Every row is a media_item row, whatever the tab joined to get here. */
        $items = $page->getCollection();
        $summaries = $items->map(fn (MediaItem $item) => $cards->inContext($item))->all();
        $resolved = $states->resolve($viewer, $summaries);

        return MediaSummaryResource::collection($page->through(
            fn (MediaItem $item, int $index) => new MediaSummaryResource($summaries[$index], $resolved[$summaries[$index]->key()]),
        ));
    }
}

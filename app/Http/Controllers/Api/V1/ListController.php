<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Library\CreateList;
use App\Actions\Library\DeleteList;
use App\Actions\Library\DuplicateList;
use App\Actions\Library\RemoveListItem;
use App\Actions\Library\ReorderListItems;
use App\Actions\Library\ReorderLists;
use App\Actions\Library\UpdateList;
use App\Catalog\MediaItemSummaries;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ReorderListItemsRequest;
use App\Http\Requests\Api\V1\ReorderListsRequest;
use App\Http\Requests\Api\V1\StoreListRequest;
use App\Http\Requests\Api\V1\UpdateListRequest;
use App\Http\Resources\Api\V1\ListResource;
use App\Models\ListItem;
use App\Models\MediaList;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

class ListController extends Controller
{
    /**
     * The user's own lists, system ones first — the same order the lists
     * page shows, without the poster collage a JSON client has no use for.
     *
     * @return AnonymousResourceCollection<int, ListResource>
     */
    public function index(#[CurrentUser] User $viewer): AnonymousResourceCollection
    {
        $lists = $viewer->lists()
            ->withCount('items')
            ->inManualOrder()
            ->get();

        return ListResource::collection($lists);
    }

    public function show(MediaList $list, MediaItemSummaries $summaries): ListResource
    {
        Gate::authorize('view', $list);

        $list->loadCount('items');
        $list->load(['items' => fn ($query) => $query
            ->with(['mediaItem.parent.parent', 'person', 'company'])
            ->orderBy('position'),
        ]);

        return ListResource::withCards($list, $summaries);
    }

    public function store(#[CurrentUser] User $viewer, StoreListRequest $request, CreateList $create): JsonResponse
    {
        $list = $create->execute($viewer, $request->title());

        return (new ListResource($list->loadCount('items')))->response()->setStatusCode(201);
    }

    /**
     * The system watchlist keeps its identity — only its visibility moves.
     * The action enforces that; the endpoint does not have to know.
     */
    public function update(UpdateListRequest $request, MediaList $list, UpdateList $update): ListResource
    {
        $update->execute($list, $request->title(), $request->description(), $request->isPublic());

        return new ListResource($list->loadCount('items'));
    }

    public function destroy(#[CurrentUser] User $viewer, MediaList $list, DeleteList $delete): Response
    {
        Gate::authorize('delete', $list);

        $delete->execute($viewer, $list);

        return response()->noContent();
    }

    public function removeItem(MediaList $list, ListItem $item, RemoveListItem $remove): Response
    {
        Gate::authorize('update', $list);

        $remove->execute($list, $item);

        return response()->noContent();
    }

    /** The user's own lists in the order given; the watchlist keeps the lead, foreign ids are ignored. */
    public function reorder(#[CurrentUser] User $viewer, ReorderListsRequest $request, ReorderLists $reorder): Response
    {
        $reorder->execute($viewer, $request->listIds());

        return response()->noContent();
    }

    /** A list's positions in the order given, numbered 1..n; ids of other lists are ignored. */
    public function reorderItems(ReorderListItemsRequest $request, MediaList $list, ReorderListItems $reorder): Response
    {
        Gate::authorize('update', $list);

        $reorder->execute($list, $request->itemIds());

        return response()->noContent();
    }

    /** A viewable list copied into the caller's own, items and order with it. */
    public function duplicate(#[CurrentUser] User $viewer, MediaList $list, DuplicateList $duplicate): JsonResponse
    {
        Gate::authorize('duplicate', $list);

        $copy = $duplicate->execute($viewer, $list->load('items'));

        return (new ListResource($copy->loadCount('items')))->response()->setStatusCode(201);
    }
}

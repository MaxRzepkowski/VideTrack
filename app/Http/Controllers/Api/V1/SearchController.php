<?php

namespace App\Http\Controllers\Api\V1;

use App\Catalog\CatalogSearch;
use App\Catalog\Data\SearchScopes;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\SearchRequest;
use App\Http\Resources\Api\V1\MediaSummaryResource;
use App\Library\LibraryStates;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\App;

/**
 * One search against one scope, exactly like the search page: a provider's
 * searchable types, or an entity scope (people, collections, companies).
 * `meta.scopes` tells a client what this instance actually offers.
 */
class SearchController extends Controller
{
    /**
     * @return AnonymousResourceCollection<int, MediaSummaryResource>
     */
    public function __invoke(#[CurrentUser] User $viewer, SearchRequest $request, CatalogSearch $search, LibraryStates $states): AnonymousResourceCollection
    {
        $scopes = $search->scopes();
        $scope = $scopes->resolve($request->scope());
        $results = $scope === null ? [] : $search->search($scope, $request->term(), App::getLocale());

        // Entities carry no media state — a person is not watched.
        $resources = SearchScopes::isEntity($scope)
            ? MediaSummaryResource::collection($results)
            : MediaSummaryResource::withStates($results, $states->resolve($viewer, $results));

        return $resources->additional(['meta' => [
            'query' => $request->term(),
            'scope' => $scope,
            'scopes' => $scopes->keys(),
        ]]);
    }
}

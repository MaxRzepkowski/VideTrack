<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Suggestions\BuildSuggestionsPage;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\ApiCollection;
use App\Http\Resources\Api\V1\SuggestionGroupResource;
use App\Http\Resources\Api\V1\SuggestionShelfResource;
use App\Models\User;
use App\Suggestions\SuggestionSources;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;

/**
 * The suggestions page: titles the user has a countable reason to catch up
 * on, a kind per rule, worked out from what is stored here. Nothing here asks
 * a provider, and nothing here notifies.
 */
class SuggestionController extends Controller
{
    /** The kinds that have something for the user, with how much waits behind each. */
    public function index(#[CurrentUser] User $viewer, Request $request, BuildSuggestionsPage $build): JsonResponse
    {
        return (new ApiCollection($build->execute($viewer, App::getLocale())['shelves'], SuggestionShelfResource::class))->response($request);
    }

    /** One kind's titles, by subject. */
    public function show(#[CurrentUser] User $viewer, Request $request, SuggestionSources $sources, string $shelf): JsonResponse
    {
        $source = $sources->find($shelf);

        abort_if($source === null, 404);

        return (new ApiCollection($source->groups($viewer, App::getLocale()), SuggestionGroupResource::class))->response($request);
    }
}

<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Media\ResolveTitlesByName;
use App\Catalog\Data\MediaSummary;
use App\Catalog\Data\TitleLookup;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\LookupTitlesRequest;
use App\Http\Resources\Api\V1\ApiCollection;
use App\Http\Resources\Api\V1\TitleLookupResource;
use App\Library\LibraryStates;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\App;

/**
 * Which of these titles does the user know? Many titles by name in one call,
 * each with its card and the user's state: the question behind every
 * recommendation, asked once instead of a search per title.
 */
class LookupController extends Controller
{
    public function __invoke(#[CurrentUser] User $viewer, LookupTitlesRequest $request, ResolveTitlesByName $resolve, LibraryStates $states): JsonResponse
    {
        $lookups = $resolve->execute($request->lookups(), App::getLocale());
        $matches = array_values(array_filter(array_map(fn (TitleLookup $lookup): ?MediaSummary => $lookup->match, $lookups)));
        $resolved = $states->resolve($viewer, $matches);

        return (new ApiCollection(
            array_map(fn (TitleLookup $lookup): TitleLookupResource => new TitleLookupResource($lookup, $lookup->match === null ? null : $resolved[$lookup->match->key()]), $lookups),
            TitleLookupResource::class,
        ))->response($request);
    }
}

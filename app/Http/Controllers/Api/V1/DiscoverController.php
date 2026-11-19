<?php

namespace App\Http\Controllers\Api\V1;

use App\Catalog\DiscoverSections;
use App\Catalog\Discovery;
use App\Catalog\ProviderCalls;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\DiscoverSectionsResource;
use App\Library\LibraryStates;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;

/**
 * What is trending, popular and coming up for one type, as the discover page
 * opens on it: asked of the type's provider, each title with the user's
 * state. A section the provider failed to fill comes back empty and
 * `meta.failed` says so.
 */
class DiscoverController extends Controller
{
    public function __invoke(#[CurrentUser] User $viewer, Request $request, Discovery $discovery, DiscoverSections $sections, LibraryStates $states, string $type): JsonResponse
    {
        abort_unless(in_array($type, $discovery->sectionCodes(), true), 404);

        $calls = new ProviderCalls;
        $found = $sections->of($type, App::getLocale(), $calls);

        return (new DiscoverSectionsResource($found, $states->resolve($viewer, $found->cards())))
            ->additional(['meta' => ['type' => $type, 'failed' => $calls->failed()]])
            ->response($request);
    }
}

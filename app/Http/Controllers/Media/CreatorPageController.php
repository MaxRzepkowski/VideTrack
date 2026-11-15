<?php

namespace App\Http\Controllers\Media;

use App\Actions\Media\ResolveCreatorPage;
use App\Catalog\Creators\CreatorKind;
use App\Catalog\Creators\CreatorRef;
use App\Http\Controllers\Controller;
use App\Library\CreatorStateQuery;
use App\Library\LibraryStates;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Support\Facades\App;
use Inertia\Inertia;
use Inertia\Response;

/** The page of a person, a studio or publisher, or a network: the route says which. */
class CreatorPageController extends Controller
{
    public function __invoke(
        #[CurrentUser] User $viewer,
        ResolveCreatorPage $resolve,
        CreatorStateQuery $creatorStates,
        LibraryStates $states,
        string $provider,
        string $ref,
        string $kind,
    ): Response {
        $creator = new CreatorRef(CreatorKind::from($kind), $provider, $ref);
        $page = $resolve->execute($creator, App::getLocale()) ?? abort(404);

        return Inertia::render('media/CreatorPage', [
            'provider' => $provider,
            'kind' => $kind,
            'creator' => $page->details,
            'fromLibrary' => $page->fromLibrary,
            'creatorStates' => $creatorStates->of($viewer, [$creator]),
            // The credit cards carry the shared action bar.
            'userStates' => $states->resolve($viewer, $page->titles()),
        ]);
    }
}

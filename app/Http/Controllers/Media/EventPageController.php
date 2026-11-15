<?php

namespace App\Http\Controllers\Media;

use App\Actions\Media\BuildEventPage;
use App\Catalog\ItemNotFoundException;
use App\Http\Controllers\Controller;
use App\Library\LibraryStates;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Inertia\Inertia;
use Inertia\Response;

/** The page of an event (a showcase), kept locally or long past. */
class EventPageController extends Controller
{
    public function __invoke(
        #[CurrentUser] User $viewer,
        BuildEventPage $build,
        LibraryStates $states,
        string $provider,
        string $ref,
    ): Response {
        try {
            $page = $build->execute($viewer, $provider, $ref);
        } catch (ItemNotFoundException) {
            abort(404);
        }

        return Inertia::render('media/EventPage', [
            'page' => $page,
            // The title cards carry the shared action bar.
            'userStates' => $states->resolve($viewer, $page->titles),
        ]);
    }
}

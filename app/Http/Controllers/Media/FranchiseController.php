<?php

namespace App\Http\Controllers\Media;

use App\Catalog\Contracts\SupportsFranchises;
use App\Catalog\ItemNotFoundException;
use App\Catalog\ProviderRegistry;
use App\Http\Controllers\Controller;
use App\Library\LibraryStates;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Support\Facades\App;
use Inertia\Inertia;
use Inertia\Response;

/** The page of a franchise, as its provider answers it: nothing is stored, nothing followed. */
class FranchiseController extends Controller
{
    public function __invoke(
        #[CurrentUser] User $viewer,
        ProviderRegistry $providers,
        LibraryStates $states,
        string $provider,
        string $ref,
    ): Response {
        $source = $providers->find($provider);
        abort_unless($source instanceof SupportsFranchises, 404);

        try {
            $franchise = $source->franchise($ref, App::getLocale());
        } catch (ItemNotFoundException) {
            abort(404);
        }

        return Inertia::render('media/FranchisePage', [
            'provider' => $provider,
            'franchise' => $franchise,
            // The game cards carry the shared action bar.
            'userStates' => $states->resolve($viewer, $franchise->titles()),
        ]);
    }
}

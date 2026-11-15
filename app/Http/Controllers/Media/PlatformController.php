<?php

namespace App\Http\Controllers\Media;

use App\Catalog\Contracts\SupportsPlatforms;
use App\Catalog\ItemNotFoundException;
use App\Catalog\ProviderRegistry;
use App\Http\Controllers\Controller;
use App\Library\LibraryStates;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Support\Facades\App;
use Inertia\Inertia;
use Inertia\Response;

/** The page of a platform, as its provider answers it: nothing is stored, nothing followed. */
class PlatformController extends Controller
{
    public function __invoke(
        #[CurrentUser] User $viewer,
        ProviderRegistry $providers,
        LibraryStates $states,
        string $provider,
        string $ref,
    ): Response {
        $source = $providers->find($provider);
        abort_unless($source instanceof SupportsPlatforms, 404);

        try {
            $platform = $source->platform($ref, App::getLocale());
        } catch (ItemNotFoundException) {
            abort(404);
        }

        return Inertia::render('media/PlatformPage', [
            'provider' => $provider,
            'platform' => $platform,
            // The game cards carry the shared action bar.
            'userStates' => $states->resolve($viewer, $platform->titles()),
        ]);
    }
}

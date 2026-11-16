<?php

namespace App\Http\Controllers\Settings;

use App\Accounts\Identity\Data\LinkedIdentity;
use App\Accounts\Identity\IdentityProviderRegistry;
use App\Actions\Users\UnlinkIdentity;
use App\Http\Controllers\Controller;
use App\Models\Identity;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The identity providers the user signs in with. Connecting is the trip to
 * the provider (IdentityController); here they are listed and disconnected.
 * Without a configured provider the page does not exist.
 */
class IdentitiesController extends Controller
{
    public function index(#[CurrentUser] User $viewer, IdentityProviderRegistry $providers): Response
    {
        abort_if($providers->codes() === [], 404);

        return Inertia::render('settings/Identities', [
            'identities' => $viewer->identities()->oldest()->get()->map(LinkedIdentity::of(...))->values()->all(),
        ]);
    }

    public function destroy(Identity $identity, UnlinkIdentity $unlink): RedirectResponse
    {
        Gate::authorize('delete', $identity);

        $unlink->execute($identity);

        return back();
    }
}

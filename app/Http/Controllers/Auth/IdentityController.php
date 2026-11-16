<?php

namespace App\Http\Controllers\Auth;

use App\Accounts\Identity\Contracts\IdentityProvider;
use App\Accounts\Identity\IdentityProviderRegistry;
use App\Accounts\Identity\IdentityRefusal;
use App\Accounts\Identity\IdentityRefused;
use App\Actions\Users\LinkIdentity;
use App\Actions\Users\SignInWithIdentity;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\ExternalServiceException;
use App\Support\PublicUrl;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\RedirectResponse as SymfonyRedirectResponse;

/**
 * The trip to an identity provider and back. A guest comes back signed in;
 * someone already signed in comes back with the identity connected to their
 * own account, never switched to the account it belongs to. Whatever goes
 * wrong lands on the page the trip started from, with the reason.
 */
class IdentityController extends Controller
{
    public function __construct(private readonly IdentityProviderRegistry $providers) {}

    public function redirect(#[CurrentUser] ?User $viewer, string $provider): SymfonyRedirectResponse
    {
        $identity = $this->provider($provider);

        try {
            return $identity->redirect($this->callbackUrl($provider));
        } catch (ExternalServiceException $e) {
            report($e);

            return $this->failed($viewer, $e->wording());
        }
    }

    public function callback(
        #[CurrentUser] ?User $viewer,
        Request $request,
        string $provider,
        SignInWithIdentity $signIn,
        LinkIdentity $link,
    ): RedirectResponse {
        $identity = $this->provider($provider);

        try {
            // The person said no at the provider, which sends back why instead of a code.
            if ($request->filled('error')) {
                throw new IdentityRefused(IdentityRefusal::Cancelled);
            }

            $claims = $identity->identify($this->callbackUrl($provider));

            if ($viewer !== null) {
                $link->execute($viewer, $claims);

                return to_route('settings.identities.index');
            }

            $user = $signIn->execute($claims);
        } catch (IdentityRefused $e) {
            return $this->failed($viewer, $e->reason());
        } catch (ExternalServiceException $e) {
            report($e);

            return $this->failed($viewer, $e->wording());
        }

        Auth::login($user, remember: true);
        $request->session()->regenerate();

        return redirect()->intended(route('dashboard', absolute: false));
    }

    private function provider(string $code): IdentityProvider
    {
        return $this->providers->find($code) ?? abort(404);
    }

    /**
     * Where the provider sends people back: the address registered with it,
     * from APP_URL, so a forged Host cannot point the trip elsewhere.
     */
    private function callbackUrl(string $provider): string
    {
        return PublicUrl::to(route('identities.callback', $provider, absolute: false));
    }

    private function failed(?User $viewer, string $reason): RedirectResponse
    {
        return to_route($viewer === null ? 'login' : 'settings.identities.index')
            ->withErrors([IdentityRefused::FIELD => $reason]);
    }
}

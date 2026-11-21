<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Integrations\Connections\ConnectionRegistry;
use App\Integrations\Connections\ConnectionRejected;
use App\Integrations\Connections\ConnectService;
use App\Integrations\Connections\Contracts\AuthorizesByRedirect;
use App\Integrations\Connections\RedirectAttempts;
use App\Integrations\Import\ImportSourceRegistry;
use App\Models\User;
use App\Support\Payload;
use App\Support\PublicUrl;
use App\Support\ServiceUnavailable;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * The trip to a service's site and back that connects it. Starting answers
 * the address for the card to send the browser to; the way back is a GET,
 * as the service sends it, and lands on the import page with the source it
 * connected for, or with the reason it did not.
 */
class ConnectionRedirectController extends Controller
{
    public function begin(#[CurrentUser] User $viewer, ConnectionRegistry $registry, RedirectAttempts $attempts, string $service): JsonResponse
    {
        try {
            $url = $attempts->begin($viewer, $this->redirectService($registry, $service), $service, $this->callbackUrl($service));
        } catch (ConnectionRejected|ServiceUnavailable $refused) {
            throw ValidationException::withMessages(['connection' => $refused->wording()]);
        }

        return response()->json(['url' => $url]);
    }

    public function callback(
        #[CurrentUser] User $viewer,
        Request $request,
        ConnectionRegistry $registry,
        ImportSourceRegistry $sources,
        RedirectAttempts $attempts,
        ConnectService $connect,
        string $service,
    ): RedirectResponse {
        $connectable = $this->redirectService($registry, $service);
        $back = to_route('settings.import.index', array_filter(['source' => $sources->readingThrough($service)?->code()]));

        $answer = Payload::stringMap($request->query());
        $state = $request->route('state');

        if (is_string($state)) {
            $answer['state'] = $state;
        }

        try {
            $grant = $attempts->complete($viewer, $connectable, $service, $answer, $this->callbackUrl($service));
        } catch (ConnectionRejected|ServiceUnavailable $refused) {
            // Under the service's own name: the page holds a card per source.
            return $back->withErrors([RedirectAttempts::errorKey($service) => $refused->wording()]);
        }

        $connect->execute($viewer, $service, $grant);

        return $back;
    }

    private function redirectService(ConnectionRegistry $registry, string $service): AuthorizesByRedirect
    {
        return $registry->capable($service, AuthorizesByRedirect::class) ?? abort(404);
    }

    /**
     * Where the service sends the user back: from APP_URL, so a forged Host
     * cannot point the trip elsewhere.
     */
    private function callbackUrl(string $service): string
    {
        return PublicUrl::to(route('settings.connections.callback', $service, absolute: false));
    }
}

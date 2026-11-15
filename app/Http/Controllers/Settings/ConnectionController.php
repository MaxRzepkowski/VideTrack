<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\ConnectionFormRequest;
use App\Integrations\Connections\ConnectAttemptStatus;
use App\Integrations\Connections\ConnectionRegistry;
use App\Integrations\Connections\ConnectionRejected;
use App\Integrations\Connections\ConnectService;
use App\Integrations\Connections\Contracts\AuthorizesByDevice;
use App\Integrations\Connections\Data\ConnectionCard;
use App\Integrations\Connections\Data\ConnectionChoice;
use App\Integrations\Connections\Data\ConnectionGrant;
use App\Integrations\Connections\DeviceAttempts;
use App\Integrations\Connections\Disconnect;
use App\Integrations\Connections\UnusableAddress;
use App\Models\ServiceConnection;
use App\Models\User;
use App\Support\ServiceUnavailable;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Connecting, and letting go of, the services imports read from. Every
 * endpoint the connect card talks to while it waits (device flow, key
 * check) answers plain JSON for a fetch() — never a redirect, which would
 * repaint the import page under the user. Only the user's own clicks
 * (auto-import, disconnect) go through Inertia.
 */
class ConnectionController extends Controller
{
    /** Starts a device flow; the page gets the code to type and where to type it. */
    public function beginDevice(#[CurrentUser] User $viewer, ConnectionRegistry $registry, DeviceAttempts $attempts, string $service): JsonResponse
    {
        try {
            $authorization = $attempts->begin($viewer, $this->deviceService($registry, $service), $service);
        } catch (ConnectionRejected|ServiceUnavailable $refused) {
            throw $this->refused($refused);
        }

        return response()->json([
            'userCode' => $authorization->userCode,
            'verificationUri' => $authorization->verificationUri,
            'verificationUriComplete' => $authorization->verificationUriComplete,
            'expiresIn' => $authorization->expiresIn,
            'interval' => $authorization->interval,
        ]);
    }

    /**
     * One look at whether the user approved. Every answer is a status, a
     * refusal included: the card reads a poll that did not go through as
     * "not yet", so a refusal answered as an error would leave it waiting.
     */
    public function pollDevice(
        #[CurrentUser] User $viewer,
        ConnectionRegistry $registry,
        DeviceAttempts $attempts,
        ConnectService $connect,
        string $service,
    ): JsonResponse {
        try {
            $result = $attempts->poll($viewer, $this->deviceService($registry, $service), $service);
        } catch (ConnectionRejected $rejected) {
            return response()->json(['status' => ConnectAttemptStatus::Failed, 'message' => $rejected->wording()]);
        }

        if ($result instanceof ConnectionGrant) {
            return response()->json([
                'status' => ConnectAttemptStatus::Connected,
                'connection' => ConnectionCard::of($connect->execute($viewer, $service, $result)),
            ]);
        }

        return response()->json(['status' => $result]);
    }

    public function cancelDevice(#[CurrentUser] User $viewer, ConnectionRegistry $registry, DeviceAttempts $attempts, string $service): JsonResponse
    {
        $this->deviceService($registry, $service);
        $attempts->cancel($viewer, $service);

        return response()->json(['status' => ConnectAttemptStatus::Cancelled]);
    }

    /**
     * Checks what the user typed with one request to the service and lists
     * the accounts to pick from. A rejection is shown under the form and
     * never retried.
     */
    public function verify(#[CurrentUser] User $viewer, ConnectionFormRequest $request): JsonResponse
    {
        try {
            $choices = $request->connectable()->verify($viewer, $request->values());
        } catch (ConnectionRejected|UnusableAddress $rejected) {
            throw $this->refused($rejected);
        }

        return response()->json([
            'choices' => array_map(fn (ConnectionChoice $choice): array => $choice->toArray(), $choices),
        ]);
    }

    public function connect(#[CurrentUser] User $viewer, ConnectionFormRequest $request, ConnectService $connect, string $service): JsonResponse
    {
        try {
            $grant = $request->connectable()->connect($viewer, $request->values(), $request->choice());
        } catch (ConnectionRejected|UnusableAddress $rejected) {
            throw $this->refused($rejected);
        }

        return response()->json([
            'status' => ConnectAttemptStatus::Connected,
            'connection' => ConnectionCard::of($connect->execute($viewer, $service, $grant)),
        ]);
    }

    public function update(Request $request, ServiceConnection $connection): RedirectResponse
    {
        Gate::authorize('update', $connection);

        $request->validate(['auto_import' => ['required', 'boolean']]);

        $connection->update(['auto_import' => $request->boolean('auto_import')]);

        return back();
    }

    public function destroy(Disconnect $disconnect, ServiceConnection $connection): RedirectResponse
    {
        Gate::authorize('delete', $connection);

        $disconnect->execute($connection);

        return back();
    }

    private function deviceService(ConnectionRegistry $registry, string $service): AuthorizesByDevice
    {
        $connectable = $registry->has($service) ? $registry->get($service) : null;

        abort_unless($connectable instanceof AuthorizesByDevice, 404);

        return $connectable;
    }

    /** A refusal, or a service out of reach, is said under the form the same way from every door. */
    private function refused(ConnectionRejected|ServiceUnavailable|UnusableAddress $refused): ValidationException
    {
        return ValidationException::withMessages(['connection' => $refused->wording()]);
    }
}

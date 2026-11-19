<?php

namespace App\Http\Controllers\Settings;

use App\Actions\Users\RenewDevice;
use App\Actions\Users\SubscribeDevice;
use App\Actions\Users\UnsubscribeDevice;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\PushDeviceRequest;
use App\Http\Requests\Settings\RenewPushDeviceRequest;
use App\Integrations\WebPush\VapidKeys;
use App\Models\PushSubscription;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * The browsers the user's notifications are pushed to: one switched on from
 * the settings page, one taken off the list, and one that subscribed again
 * by itself when the app started.
 */
class PushDeviceController extends Controller
{
    public function store(#[CurrentUser] User $viewer, PushDeviceRequest $request, SubscribeDevice $subscribe): RedirectResponse
    {
        $subscribe->execute($viewer, $request->subscription());

        return back();
    }

    public function destroy(PushSubscription $device, UnsubscribeDevice $unsubscribe): RedirectResponse
    {
        Gate::authorize('delete', $device);

        $unsubscribe->execute($device);

        return back();
    }

    /** The key a browser subscribes with: the app compares it with the one its subscription was made with. */
    public function key(VapidKeys $keys): JsonResponse
    {
        $vapid = $keys->current();

        abort_if($vapid === null, 404);

        return response()->json(['data' => ['publicKey' => $vapid->publicKey]]);
    }

    /** Answered in the background: a renewal the page does not wait for, and no page to redraw. */
    public function renew(#[CurrentUser] User $viewer, RenewPushDeviceRequest $request, RenewDevice $renew): Response
    {
        abort_if($renew->execute($viewer, $request->previousEndpoint(), $request->subscription()) === null, 404);

        return response()->noContent();
    }
}

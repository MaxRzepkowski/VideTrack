<?php

namespace App\Http\Controllers\Settings;

use App\Actions\Users\AddChannel;
use App\Actions\Users\BuildChannelsPage;
use App\Actions\Users\TestChannel;
use App\Actions\Users\UpdateChannel;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\ChannelRequest;
use App\Models\NotificationChannel;
use App\Models\User;
use App\Notifications\Channels\ChannelRegistry;
use App\Support\Delivery\DeliveryAnswer;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Where notifications go beyond the bell: the user's channels, each with its
 * own address, mode and switch.
 */
class ChannelsController extends Controller
{
    public function index(#[CurrentUser] User $viewer, BuildChannelsPage $page): Response
    {
        return Inertia::render('settings/Channels', ['page' => $page->execute($viewer)]);
    }

    public function store(#[CurrentUser] User $viewer, ChannelRequest $request, AddChannel $add): RedirectResponse
    {
        $add->execute($viewer, $request->driverCode(), $request->channelKind(), $request->settings());

        return back();
    }

    public function update(ChannelRequest $request, NotificationChannel $channel, UpdateChannel $update): RedirectResponse
    {
        $update->execute($channel, $request->kind(), $request->settings());

        return back();
    }

    public function destroy(NotificationChannel $channel): RedirectResponse
    {
        Gate::authorize('delete', $channel);

        $channel->delete();

        return back();
    }

    /** A failed test is a validation error on the channel; its row tells how it failed. */
    public function test(NotificationChannel $channel, ChannelRegistry $drivers, TestChannel $test): RedirectResponse
    {
        Gate::authorize('update', $channel);
        // A driver this instance no longer has has nothing to test with.
        abort_unless($drivers->has($channel->driver), 404);

        if ($test->execute($channel) !== DeliveryAnswer::Delivered) {
            throw ValidationException::withMessages(['test' => __('settings.the_test_message_did_not_arrive')]);
        }

        return back();
    }
}

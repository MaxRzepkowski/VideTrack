<?php

namespace App\Http\Controllers\Settings;

use App\Actions\Users\AddOutgoingWebhook;
use App\Actions\Users\BuildOutgoingWebhooksPage;
use App\Actions\Users\RemoveOutgoingWebhook;
use App\Actions\Users\TestOutgoingWebhook;
use App\Actions\Users\UpdateOutgoingWebhook;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\OutgoingWebhookRequest;
use App\Models\OutgoingWebhook;
use App\Models\User;
use App\Support\Delivery\DeliveryAnswer;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Addresses of the user's own that hear about what happens in their
 * library, each with its own events, secret and switch.
 */
class OutgoingWebhooksController extends Controller
{
    public function index(#[CurrentUser] User $viewer, BuildOutgoingWebhooksPage $page): Response
    {
        // The page carries a fresh signing secret, and the browser keeps
        // props in its history: encrypted there, unreadable once signed out.
        Inertia::encryptHistory();

        return Inertia::render('settings/Webhooks', ['page' => $page->execute($viewer)]);
    }

    public function store(#[CurrentUser] User $viewer, OutgoingWebhookRequest $request, AddOutgoingWebhook $add): RedirectResponse
    {
        $add->execute($viewer, $request->settings());

        return back();
    }

    public function update(OutgoingWebhookRequest $request, OutgoingWebhook $webhook, UpdateOutgoingWebhook $update): RedirectResponse
    {
        $update->execute($webhook, $request->settings());

        return back();
    }

    public function destroy(OutgoingWebhook $webhook, RemoveOutgoingWebhook $remove): RedirectResponse
    {
        Gate::authorize('delete', $webhook);

        $remove->execute($webhook);

        return back();
    }

    /** A failed test is a validation error on the webhook; its row tells how it failed. */
    public function test(OutgoingWebhook $webhook, TestOutgoingWebhook $test): RedirectResponse
    {
        Gate::authorize('update', $webhook);

        if ($test->execute($webhook) !== DeliveryAnswer::Delivered) {
            throw ValidationException::withMessages(['test' => __('settings.the_test_message_did_not_arrive')]);
        }

        return back();
    }
}

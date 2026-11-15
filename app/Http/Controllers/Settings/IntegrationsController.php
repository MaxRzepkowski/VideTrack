<?php

namespace App\Http\Controllers\Settings;

use App\Actions\Scrobble\AddWebhookEndpoint;
use App\Actions\Scrobble\BuildIntegrationsPage;
use App\Actions\Scrobble\IssueWebhookToken;
use App\Actions\Scrobble\TestWebhookEndpoint;
use App\Actions\Scrobble\UpdateWebhookEndpoint;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\UpdateWebhookEndpointRequest;
use App\Integrations\Scrobble\Contracts\ProvidesSamplePayload;
use App\Integrations\Scrobble\ScrobbleSourceRegistry;
use App\Models\User;
use App\Models\WebhookEndpoint;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Where the user connects the things that already know what they watched.
 */
class IntegrationsController extends Controller
{
    public function index(#[CurrentUser] User $viewer, BuildIntegrationsPage $page): Response
    {
        return Inertia::render('settings/Integrations', ['sources' => $page->execute($viewer)]);
    }

    public function store(#[CurrentUser] User $viewer, ScrobbleSourceRegistry $registry, AddWebhookEndpoint $add, string $source): RedirectResponse
    {
        abort_unless($registry->has($source), 404);

        $add->execute($viewer, $source);

        return back();
    }

    public function update(UpdateWebhookEndpointRequest $request, WebhookEndpoint $endpoint, UpdateWebhookEndpoint $update): RedirectResponse
    {
        Gate::authorize('update', $endpoint);

        /** @var array{label: string|null, enabled: bool, server_username: string|null} $validated */
        $validated = $request->validated();

        $update->execute($endpoint, $validated['label'] ?? null, $validated['enabled'], $validated['server_username'] ?? null);

        return back();
    }

    /**
     * A new token, the same endpoint. Every address handed out so far stops
     * working, which is the point — the log and the settings are not what
     * leaked.
     */
    public function rotate(WebhookEndpoint $endpoint, IssueWebhookToken $issueToken): RedirectResponse
    {
        Gate::authorize('update', $endpoint);

        $issueToken->execute($endpoint);

        return back();
    }

    public function destroy(WebhookEndpoint $endpoint): RedirectResponse
    {
        Gate::authorize('delete', $endpoint);

        $endpoint->delete();

        return back();
    }

    /** Runs the source's example payload through parsing and placing, and logs the verdict. */
    public function test(
        #[CurrentUser] User $viewer,
        ScrobbleSourceRegistry $registry,
        TestWebhookEndpoint $test,
        WebhookEndpoint $endpoint,
    ): RedirectResponse {
        Gate::authorize('update', $endpoint);

        $source = $registry->get($endpoint->source);

        abort_unless($source instanceof ProvidesSamplePayload, 404);

        $test->execute($viewer, $endpoint, $source);

        return back();
    }
}

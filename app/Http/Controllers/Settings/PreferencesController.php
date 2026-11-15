<?php

namespace App\Http\Controllers\Settings;

use App\Actions\Users\SetDiscordWebhook;
use App\Actions\Users\UpdatePreferences;
use App\Catalog\Contracts\SupportsWatchProviders;
use App\Catalog\ProviderCalls;
use App\Catalog\ProviderRegistry;
use App\Catalog\ViewerRegion;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\PreferencesUpdateRequest;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class PreferencesController extends Controller
{
    private const WEBHOOK_ENDING_LENGTH = 4;

    public function edit(#[CurrentUser] User $viewer, ProviderRegistry $providers): Response
    {
        return Inertia::render('settings/Preferences', [
            'regions' => fn (): array => $this->regions($providers),
            'defaultRegion' => ViewerRegion::default(App::getLocale()),
            // A credential never comes back to the page: its last characters
            // tell the user which webhook is saved, a new address replaces it.
            'discordWebhookEnding' => $this->ending($viewer->discordWebhookUrl()),
        ]);
    }

    public function update(
        #[CurrentUser] User $viewer,
        PreferencesUpdateRequest $request,
        UpdatePreferences $preferences,
        SetDiscordWebhook $discordWebhook,
    ): RedirectResponse {
        $preferences->execute($viewer, $request->preferences());

        if ($request->has('discord_webhook_url')) {
            $discordWebhook->execute($viewer, $request->webhookUrl());
        }

        return back();
    }

    /**
     * The countries to choose from — those the watch-provider service knows;
     * an unreachable provider only leaves the select with the automatic
     * option.
     *
     * @return array<string, string>
     */
    private function regions(ProviderRegistry $providers): array
    {
        $provider = $providers->firstSupporting(SupportsWatchProviders::class);

        if (! $provider instanceof SupportsWatchProviders) {
            return [];
        }

        return (new ProviderCalls)->attempt(fn (): array => $provider->watchRegions(App::getLocale()), []);
    }

    private function ending(?string $webhook): ?string
    {
        return $webhook === null ? null : Str::substr($webhook, -self::WEBHOOK_ENDING_LENGTH);
    }
}

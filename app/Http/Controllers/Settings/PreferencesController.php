<?php

namespace App\Http\Controllers\Settings;

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
use Inertia\Inertia;
use Inertia\Response;

class PreferencesController extends Controller
{
    public function edit(ProviderRegistry $providers): Response
    {
        return Inertia::render('settings/Preferences', [
            'regions' => fn (): array => $this->regions($providers),
            'defaultRegion' => ViewerRegion::default(App::getLocale()),
        ]);
    }

    public function update(#[CurrentUser] User $viewer, PreferencesUpdateRequest $request, UpdatePreferences $preferences): RedirectResponse
    {
        $preferences->execute($viewer, $request->preferences());

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
}

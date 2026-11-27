<?php

namespace App\Tasks\Attention;

use App\Catalog\Contracts\SupportsHealthProbe;
use App\Catalog\ProviderHealth;
use App\Catalog\ProviderRegistry;
use App\Catalog\ProviderState;
use App\Tasks\Attention\Contracts\AttentionSource;
use App\Tasks\Attention\Data\AttentionItem;

/**
 * A metadata provider turning the instance's key away: waiting does not end
 * it, only a new key does. An outage or a limit passes on its own and is not
 * the admin's to fix.
 */
final readonly class RefusedProviderKeys implements AttentionSource
{
    public function __construct(
        private ProviderRegistry $providers,
        private ProviderHealth $health,
    ) {}

    public function items(): array
    {
        $items = [];

        foreach ($this->providers->allSupporting(SupportsHealthProbe::class) as $code => $provider) {
            $status = $this->health->of($code);

            if ($provider instanceof SupportsHealthProbe && $status->state === ProviderState::KeyRejected) {
                $items[] = new AttentionItem(
                    title: __('tasks.provider_refuses_its_key', ['provider' => $provider->serviceName()]),
                    detail: __('tasks.titles_from_provider_stop_refreshing', ['variables' => implode(', ', $provider->credentialVariables())]),
                    since: $status->since?->toIso8601String(),
                );
            }
        }

        return $items;
    }
}

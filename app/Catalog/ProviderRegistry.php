<?php

namespace App\Catalog;

use App\Catalog\Contracts\MetadataProvider;
use App\Support\ServiceRegistry;

/**
 * The metadata providers behind this instance's media types.
 *
 * @extends ServiceRegistry<MetadataProvider>
 */
final class ProviderRegistry extends ServiceRegistry
{
    protected function kind(): string
    {
        return 'metadata provider';
    }

    public function forType(MediaTypeDefinition $definition): MetadataProvider
    {
        return $this->get($definition->provider);
    }

    /**
     * First registered provider implementing a capability interface.
     *
     * @param  class-string  $capability
     */
    public function firstSupporting(string $capability): ?MetadataProvider
    {
        $codes = $this->codesImplementing($capability);

        return $codes === [] ? null : $this->get($codes[0]);
    }

    /**
     * Every registered provider implementing a capability interface, in
     * registration order, by its code.
     *
     * @param  class-string  $capability
     * @return array<string, MetadataProvider>
     */
    public function allSupporting(string $capability): array
    {
        $providers = [];

        foreach ($this->codesImplementing($capability) as $code) {
            $providers[$code] = $this->get($code);
        }

        return $providers;
    }
}

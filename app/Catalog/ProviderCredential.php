<?php

namespace App\Catalog;

/**
 * A credential a provider cannot be asked without. A module registers only
 * when its keys are set, so a gap here is a misconfigured instance and
 * reads as the service being unavailable.
 */
final class ProviderCredential
{
    /**
     * @throws ProviderException
     */
    public static function read(string $configKey, string $envName): string
    {
        $value = config($configKey);

        if (! is_string($value) || $value === '') {
            throw new ProviderException("{$envName} is not configured.");
        }

        return $value;
    }
}

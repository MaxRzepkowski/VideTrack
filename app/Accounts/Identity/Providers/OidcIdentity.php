<?php

namespace App\Accounts\Identity\Providers;

/**
 * The operator's own OpenID Connect server (Keycloak, Authelia, Authentik,
 * Pocket ID…), named on the button as the operator calls it.
 */
final class OidcIdentity extends OpenIdConnectIdentity
{
    public const CODE = 'oidc';

    public function code(): string
    {
        return self::CODE;
    }

    public function label(): string
    {
        $label = config('tracker.auth.providers.oidc.label');

        return is_string($label) && $label !== '' ? $label : trans('account.single_sign_on');
    }

    public function glyph(): string
    {
        return 'openid';
    }

    protected function issuer(): string
    {
        return config()->string('tracker.auth.providers.oidc.issuer');
    }

    protected function clientId(): string
    {
        return config()->string('tracker.auth.providers.oidc.client_id');
    }

    protected function clientSecret(): string
    {
        return config()->string('tracker.auth.providers.oidc.client_secret');
    }
}

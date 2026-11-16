<?php

namespace App\Accounts\Identity\Providers;

/**
 * GitLab accounts, on gitlab.com or a GitLab of one's own. GitLab is an
 * OpenID Connect server, which Socialite's own GitLab driver does not use:
 * that one reads an API version GitLab no longer serves.
 */
final class GitlabIdentity extends OpenIdConnectIdentity
{
    public const CODE = 'gitlab';

    public function code(): string
    {
        return self::CODE;
    }

    public function label(): string
    {
        return 'GitLab';
    }

    public function glyph(): string
    {
        return 'gitlab';
    }

    protected function issuer(): string
    {
        return config()->string('tracker.auth.providers.gitlab.url');
    }

    protected function clientId(): string
    {
        return config()->string('tracker.auth.providers.gitlab.client_id');
    }

    protected function clientSecret(): string
    {
        return config()->string('tracker.auth.providers.gitlab.client_secret');
    }
}

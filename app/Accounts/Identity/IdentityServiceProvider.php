<?php

namespace App\Accounts\Identity;

use App\Accounts\Identity\Providers\GithubIdentity;
use App\Accounts\Identity\Providers\GitlabIdentity;
use App\Accounts\Identity\Providers\GoogleIdentity;
use App\Accounts\Identity\Providers\OidcIdentity;
use Illuminate\Support\ServiceProvider;

/**
 * The identity providers the operator configured, the instance's own server
 * first: where one runs, it is how its people sign in everywhere.
 */
final class IdentityServiceProvider extends ServiceProvider
{
    private const CREDENTIALS = ['client_id', 'client_secret'];

    public function register(): void
    {
        $this->callAfterResolving(IdentityProviderRegistry::class, function (IdentityProviderRegistry $registry): void {
            // Each with the settings it cannot work without.
            $providers = [
                [OidcIdentity::CODE, OidcIdentity::class, ['issuer', ...self::CREDENTIALS]],
                [GoogleIdentity::CODE, GoogleIdentity::class, self::CREDENTIALS],
                [GithubIdentity::CODE, GithubIdentity::class, self::CREDENTIALS],
                [GitlabIdentity::CODE, GitlabIdentity::class, ['url', ...self::CREDENTIALS]],
            ];

            foreach ($providers as [$code, $class, $required]) {
                if ($this->configured($code, $required)) {
                    $registry->register($code, $class);
                }
            }
        });
    }

    /**
     * @param  list<string>  $required
     */
    private function configured(string $code, array $required): bool
    {
        foreach ($required as $key) {
            if (blank(config("tracker.auth.providers.{$code}.{$key}"))) {
                return false;
            }
        }

        return true;
    }
}

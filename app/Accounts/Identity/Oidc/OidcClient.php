<?php

namespace App\Accounts\Identity\Oidc;

use App\Accounts\Identity\Data\OidcEndpoints;
use App\Support\Payload;
use GuzzleHttp\RequestOptions;
use Laravel\Socialite\Two\AbstractProvider;
use Laravel\Socialite\Two\User;
use LogicException;

/**
 * The instance's client of an OpenID Connect server: Socialite's
 * authorization code flow at the endpoints its discovery document names,
 * sent through the Guzzle client of IdentityServerClient. Who signed in is read
 * from the userinfo endpoint with the access token, which came straight
 * from the token endpoint over TLS: OpenID Connect Core 3.1.3.7 lets that
 * channel vouch for the answer, so the ID token's signature is not checked.
 */
final class OidcClient extends AbstractProvider
{
    /** @var list<string> */
    protected $scopes = ['openid', 'profile', 'email'];

    protected $scopeSeparator = ' ';

    private ?OidcEndpoints $endpoints = null;

    public function at(OidcEndpoints $endpoints): self
    {
        $this->endpoints = $endpoints;

        return $this;
    }

    /**
     * @param  string  $state
     */
    protected function getAuthUrl($state): string
    {
        return $this->buildAuthUrlFromBase($this->endpoints()->authorization, $state);
    }

    protected function getTokenUrl(): string
    {
        return $this->endpoints()->token;
    }

    /**
     * @param  string|null  $token
     * @return array<array-key, mixed>
     */
    protected function getUserByToken($token): array
    {
        $response = $this->getHttpClient()->get($this->endpoints()->userinfo, [
            RequestOptions::HEADERS => [
                'Accept' => 'application/json',
                'Authorization' => 'Bearer '.$token,
            ],
        ]);

        return Payload::map(json_decode((string) $response->getBody(), true));
    }

    /**
     * @param  array<array-key, mixed>  $user
     */
    protected function mapUserToObject(array $user): User
    {
        return (new User)->setRaw($user)->map([
            'id' => Payload::id($user['sub'] ?? null),
            'nickname' => Payload::string($user, 'preferred_username'),
            'name' => Payload::string($user, 'name'),
            'email' => Payload::string($user, 'email'),
        ]);
    }

    private function endpoints(): OidcEndpoints
    {
        return $this->endpoints ?? throw new LogicException('An OpenID Connect driver is used only at the endpoints of its server.');
    }
}

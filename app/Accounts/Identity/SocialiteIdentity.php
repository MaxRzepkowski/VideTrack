<?php

namespace App\Accounts\Identity;

use App\Accounts\Identity\Contracts\IdentityProvider;
use App\Accounts\Identity\Data\IdentityClaims;
use App\Support\Payload;
use App\Support\ServiceRejected;
use App\Support\ServiceUnavailable;
use GuzzleHttp\Exception\BadResponseException;
use GuzzleHttp\Exception\ConnectException;
use Laravel\Socialite\SocialiteManager;
use Laravel\Socialite\Two\AbstractProvider;
use Laravel\Socialite\Two\InvalidStateException;
use Laravel\Socialite\Two\User as SocialiteUser;
use LogicException;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * A provider signed in with through a Socialite driver: the trip there with
 * a state and a PKCE challenge kept in the session, and the way back read
 * into the claims every provider gives. Its failures leave as the app's own
 * exceptions, without the request in their message.
 */
abstract class SocialiteIdentity implements IdentityProvider
{
    public function __construct(
        private readonly SocialiteManager $socialite,
        protected readonly IdentityServerClient $server,
    ) {}

    /**
     * The driver for one trip that comes back to $callbackUrl.
     *
     * @throws ServiceRejected|ServiceUnavailable
     */
    abstract protected function driver(string $callbackUrl): AbstractProvider;

    abstract protected function claims(SocialiteUser $user, string $subject): IdentityClaims;

    public function redirect(string $callbackUrl): RedirectResponse
    {
        return $this->driver($callbackUrl)->redirect();
    }

    public function identify(string $callbackUrl): IdentityClaims
    {
        $driver = $this->driver($callbackUrl);

        try {
            $user = $driver->user();
        } catch (InvalidStateException) {
            throw new IdentityRefused(IdentityRefusal::Interrupted);
        } catch (ConnectException) {
            throw new ServiceUnavailable('account.identity_server_unreachable');
        } catch (BadResponseException $e) {
            $status = $e->getResponse()->getStatusCode();

            throw $status >= 500
                ? new ServiceUnavailable('account.identity_server_unreachable', ['status' => $status])
                : new ServiceRejected('account.identity_server_refused', ['status' => $status]);
        }

        if (! $user instanceof SocialiteUser) {
            throw new ServiceRejected('account.identity_server_refused');
        }

        $subject = Payload::id($user->getId()) ?? throw new ServiceRejected('account.identity_server_refused');

        return $this->claims($user, $subject);
    }

    /**
     * @template T of AbstractProvider
     *
     * @param  class-string<T>  $driver
     * @return T
     */
    protected function build(string $driver, string $callbackUrl, string $clientId, string $clientSecret): AbstractProvider
    {
        $built = $this->socialite->buildProvider($driver, [
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'redirect' => $callbackUrl,
        ]);

        if (! $built instanceof $driver) {
            throw new LogicException("Socialite built another driver than [{$driver}].");
        }

        $built->setHttpClient($this->server->guzzle())->enablePKCE();

        return $built;
    }
}

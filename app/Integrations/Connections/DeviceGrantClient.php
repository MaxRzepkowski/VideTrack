<?php

namespace App\Integrations\Connections;

use App\Integrations\Connections\Data\DeviceAuthorization;
use App\Integrations\Connections\Data\DeviceGrant;
use App\Support\HttpRetry;
use App\Support\Payload;
use Illuminate\Http\Client\Response;

/**
 * The device flow (RFC 8628) the same for every service that offers it:
 * asking for a code and polling until the user approves. The grant's tokens
 * are TokenGrantClient's to keep fresh from then on.
 *
 * Nothing here retries an answered 4xx.
 */
class DeviceGrantClient
{
    /** How often to poll when the service does not say (RFC 8628, 3.2). */
    public const DEFAULT_INTERVAL_SECONDS = 5;

    /** How long a code holds when the service does not say. */
    private const DEFAULT_CODE_SECONDS = 900;

    public function __construct(private readonly TokenGrantClient $tokens) {}

    /**
     * @throws ConnectionRejected|GrantUnavailable
     */
    public function begin(DeviceGrant $grant): DeviceAuthorization
    {
        $response = $this->tokens->post($grant->tokenGrant(), $grant->devicePath, ['scope' => $grant->scope]);

        $this->throwIfDown($grant, $response);

        if ($this->refusesTheApp($response)) {
            throw new ConnectionRejected($grant->rejectedKey);
        }

        $answer = Payload::map($response->json());
        $deviceCode = Payload::string($answer, 'device_code');
        $userCode = Payload::string($answer, 'user_code');
        $verificationUri = Payload::string($answer, 'verification_uri') ?? $grant->verificationUri;

        if (! $response->successful() || $deviceCode === null || $userCode === null || $verificationUri === null) {
            throw new ConnectionRejected($grant->notStartedKey);
        }

        return new DeviceAuthorization(
            deviceCode: $deviceCode,
            userCode: $userCode,
            verificationUri: $verificationUri,
            verificationUriComplete: Payload::string($answer, 'verification_uri_complete'),
            expiresIn: Payload::int($answer, 'expires_in') ?? self::DEFAULT_CODE_SECONDS,
            interval: Payload::int($answer, 'interval') ?? self::DEFAULT_INTERVAL_SECONDS,
        );
    }

    /**
     * One poll: the tokens once approved, otherwise what to do next. A
     * refusal ends the attempt like an expired code; a service that never
     * reports one stays pending until the caller's deadline.
     *
     * @return array{access_token: string, refresh_token: string, expires_at: int}|DevicePoll
     *
     * @throws ConnectionRejected|GrantUnavailable
     */
    public function poll(DeviceGrant $grant, string $deviceCode): array|DevicePoll
    {
        $response = $this->tokens->post($grant->tokenGrant(), $grant->tokenPath, [
            'grant_type' => 'urn:ietf:params:oauth:grant-type:device_code',
            'device_code' => $deviceCode,
        ]);

        if ($response->successful()) {
            return $this->tokens->readTokens($grant->tokenGrant(), $response, null);
        }

        // Down for a moment is not "the code expired": the next poll asks again.
        $this->throwIfDown($grant, $response);

        if ($this->refusesTheApp($response)) {
            throw new ConnectionRejected($grant->rejectedKey);
        }

        return match ($this->error($response)) {
            OAuthError::AuthorizationPending => DevicePoll::Pending,
            OAuthError::SlowDown => DevicePoll::SlowDown,
            default => DevicePoll::Expired,
        };
    }

    /**
     * The service does not know the instance's app, or does not let it use
     * this grant: only the operator can change that.
     */
    private function refusesTheApp(Response $response): bool
    {
        return $response->status() === 401
            || in_array($this->error($response), [OAuthError::InvalidClient, OAuthError::UnauthorizedClient], true);
    }

    private function error(Response $response): ?OAuthError
    {
        return Payload::enum(Payload::map($response->json()), 'error', OAuthError::class);
    }

    /**
     * @throws GrantUnavailable
     */
    private function throwIfDown(DeviceGrant $grant, Response $response): void
    {
        if (HttpRetry::isOutage($response)) {
            throw new GrantUnavailable('import.service_answered_try_later', ['service' => $grant->service, 'status' => $response->status()]);
        }
    }
}

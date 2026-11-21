<?php

namespace App\Integrations\Connections\Data;

/**
 * Where one service keeps its OAuth device grant (RFC 8628) and how its
 * refusals read: what a client hands DeviceGrantClient so the code request,
 * the polling and the token refresh run the same way for every service.
 * The messages are translation keys.
 */
final readonly class DeviceGrant
{
    public function __construct(
        /** The service's own name, for the messages that name it. */
        public string $service,
        public string $baseUrl,
        public string $clientId,
        public string $scope,
        public string $devicePath,
        public string $tokenPath,
        public string $revokePath,
        /** The instance's app is unknown to the service or not allowed this grant. */
        public string $rejectedKey,
        /** The service answered the code request with nothing to show. */
        public string $notStartedKey,
        /** The grant is gone: revoked, or its refresh token lapsed. */
        public string $expiredKey,
        /** Where to send the user when the service leaves it out of its answer. */
        public ?string $verificationUri = null,
    ) {}
}

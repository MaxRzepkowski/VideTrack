<?php

namespace App\Integrations\Connections\Data;

/**
 * Where a service's OAuth tokens are refreshed and revoked, and how the
 * instance's app signs those requests: what TokenGrantClient needs to keep a
 * connection's access token fresh, whichever flow made the grant. The
 * message is a translation key.
 */
final readonly class TokenGrant
{
    public function __construct(
        /** The service's own name, for the messages that name it. */
        public string $service,
        public string $baseUrl,
        public string $clientId,
        /** A confidential app's secret, sent in the form body; none for a public app. */
        public ?string $clientSecret,
        public string $tokenPath,
        public string $revokePath,
        /** The grant is gone: revoked, or its refresh token lapsed. */
        public string $expiredKey,
    ) {}
}

<?php

namespace App\Integrations\Connections\Data;

use App\Integrations\Connections\TokenRequestStyle;

/**
 * Where a service's tokens are refreshed and revoked, and how the instance's
 * app signs those requests: what TokenGrantClient needs to keep a
 * connection's access token fresh, whichever flow made the grant. The
 * message is a translation key.
 */
final readonly class TokenGrant
{
    /** Refresh this long before expiry rather than on the first 401: a day, for a token that lives a week. */
    public const DEFAULT_REFRESH_MARGIN_SECONDS = 86400;

    public function __construct(
        /** The service's own name, for the messages that name it. */
        public string $service,
        public string $baseUrl,
        /** Who the app is to the service: its OAuth client id, or the publishable key of a Supabase project. */
        public string $clientId,
        /** A confidential app's secret, sent in the form body; none for a public app. */
        public ?string $clientSecret,
        public string $tokenPath,
        public string $revokePath,
        /** The grant is gone: revoked, or its refresh token lapsed. */
        public string $expiredKey,
        public TokenRequestStyle $style = TokenRequestStyle::OAuthForm,
        /** How long before expiry the token is refreshed rather than relied on: minutes, for one that lives an hour. */
        public int $refreshMarginSeconds = self::DEFAULT_REFRESH_MARGIN_SECONDS,
        /** A server the user runs, held to the IP its address was approved at; none for a service's own host. */
        public ?PinnedAddress $pinned = null,
    ) {}
}

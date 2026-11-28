<?php

namespace App\Integrations\Connections;

/**
 * How a service's token endpoint is spoken to: the same refresh and the same
 * rotation, two dialects.
 */
enum TokenRequestStyle
{
    /** OAuth 2.0 (RFC 6749): a form signed with the app's client id, and its secret when it has one; revoked by RFC 7009. */
    case OAuthForm;

    /**
     * Supabase Auth: JSON under the project's publishable key in an `apikey`
     * header, the grant type in the address; a session ends by its own
     * access token.
     */
    case SupabaseJson;
}

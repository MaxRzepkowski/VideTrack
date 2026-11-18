<?php

namespace App\Integrations\OutgoingWebhooks;

use InvalidArgumentException;

/**
 * Signing as Standard Webhooks (standardwebhooks.com) defines it, so a
 * receiver checks a message with any of that standard's libraries: an
 * HMAC-SHA256 of "id.timestamp.body" under a `whsec_` secret, sent as `v1,`
 * and the signature in base64.
 */
final class WebhookSignature
{
    private const PREFIX = 'whsec_';

    /** How many random bytes a new secret holds; the standard takes 24 to 64. */
    private const SECRET_BYTES = 32;

    public const MIN_BYTES = 24;

    public const MAX_BYTES = 64;

    private const VERSION = 'v1';

    /** A fresh secret, in the form the standard's libraries read. */
    public static function newSecret(): string
    {
        return self::PREFIX.base64_encode(random_bytes(self::SECRET_BYTES));
    }

    /** Whether a secret typed or pasted in is one the standard's libraries accept. */
    public static function isValidSecret(string $secret): bool
    {
        $key = self::key($secret);

        return $key !== null && strlen($key) >= self::MIN_BYTES && strlen($key) <= self::MAX_BYTES;
    }

    /** The `webhook-signature` header of a message: its body signed with its id and the time it is sent. */
    public static function sign(string $secret, string $id, int $timestamp, string $body): string
    {
        $key = self::key($secret) ?? throw new InvalidArgumentException('A webhook secret is checked when the webhook is saved.');

        return self::VERSION.','.base64_encode(hash_hmac('sha256', "{$id}.{$timestamp}.{$body}", $key, true));
    }

    /** The secret's raw bytes, or null when it is not `whsec_` and base64. */
    private static function key(string $secret): ?string
    {
        if (! str_starts_with($secret, self::PREFIX)) {
            return null;
        }

        $key = base64_decode(substr($secret, strlen(self::PREFIX)), true);

        return $key === false || $key === '' ? null : $key;
    }
}

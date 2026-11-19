<?php

namespace App\Integrations\WebPush;

/**
 * The key pair the instance signs every push with, and the address a push
 * service may reach its operator at.
 */
final readonly class VapidCredentials
{
    /**
     * @param  string  $publicKey  base64url, the uncompressed P-256 point browsers subscribe with
     * @param  string  $privateKey  base64url
     * @param  string  $subject  an https: address or a mailto:
     */
    public function __construct(
        public string $publicKey,
        public string $privateKey,
        public string $subject,
    ) {}
}

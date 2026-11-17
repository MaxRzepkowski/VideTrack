<?php

namespace App\Notifications\Channels\Data;

/** A day of notifications for one channel: sections in the order the digest lists them. */
final readonly class ChannelDigest
{
    /**
     * @param  non-empty-list<DigestSection>  $sections
     */
    public function __construct(
        public string $heading,
        public array $sections,
        public string $closing,
    ) {}
}

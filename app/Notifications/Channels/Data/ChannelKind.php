<?php

namespace App\Notifications\Channels\Data;

use App\Support\Forms\CredentialField;

/**
 * One service a driver delivers to — Discord, an e-mail address, each of the
 * services Apprise knows — and what a channel of it is configured with.
 */
final readonly class ChannelKind
{
    /**
     * @param  string  $code  unique within its driver
     * @param  list<CredentialField>  $fields  worded in the reader's language
     * @param  string|null  $setupUrl  the service's own guide to what the fields want
     */
    public function __construct(
        public string $code,
        public string $label,
        public array $fields,
        public ?string $setupUrl = null,
        /** Offered first, before the rest in alphabetical order. */
        public bool $popular = false,
    ) {}
}

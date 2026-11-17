<?php

namespace App\Notifications\Channels\Data;

use App\Notifications\Channels\ChannelMode;

/** What the user sent about a channel; a null field was not sent. */
final readonly class ChannelSettings
{
    /**
     * @param  string|null  $label  empty names the channel after its kind
     * @param  array<string, string>|null  $config  the whole of a new address, as its driver keeps it
     */
    public function __construct(
        public ?string $label,
        public ?ChannelMode $mode,
        public ?bool $enabled,
        public ?array $config,
    ) {}
}

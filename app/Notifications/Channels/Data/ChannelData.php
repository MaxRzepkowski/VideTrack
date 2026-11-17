<?php

namespace App\Notifications\Channels\Data;

use App\Notifications\Channels\ChannelMode;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One of the user's channels as the settings page lists it: the plain values
 * of its fields come back to be changed, its secrets never do.
 */
#[TypeScript]
final readonly class ChannelData
{
    /**
     * @param  array<string, string>  $values  field key => saved value, for every field that is not a secret
     */
    public function __construct(
        public int $id,
        public string $driver,
        public string $kind,
        /** The service's name; null while the instance cannot say (its Apprise out of reach). */
        public ?string $kindLabel,
        public string $label,
        public ChannelMode $mode,
        public bool $enabled,
        public array $values,
        /** How the last message failed, worded on this side; null once one arrived. */
        public ?string $lastFailure,
    ) {}
}

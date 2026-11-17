<?php

namespace App\Notifications\Channels;

use RuntimeException;

/**
 * What the user typed cannot make a channel: a value of the wrong form, a
 * server in a private network, too few fields for the service. Named under
 * the field it is about, in the reader's language.
 */
final class UnusableChannelSettings extends RuntimeException
{
    /**
     * @param  string  $field  the field key, or '' for the form as a whole
     * @param  array<string, string>  $replace
     */
    public function __construct(public readonly string $field, public readonly string $key, private readonly array $replace = [])
    {
        parent::__construct($key);
    }

    public function wording(): string
    {
        return __($this->key, $this->replace);
    }
}

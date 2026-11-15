<?php

namespace App\Integrations\Connections;

use RuntimeException;

/**
 * An address a user typed that the server will not call. No service was
 * asked: this is the instance's own answer, so it sits outside the
 * external-service exceptions. The message is a translation key.
 */
abstract class UnusableAddress extends RuntimeException
{
    /** @param  string  $key  the translation key of why */
    public function __construct(public readonly string $key)
    {
        parent::__construct($key);
    }

    /** Why, in the reader's language (the app's when none is given). */
    public function wording(?string $locale = null): string
    {
        return __($this->key, [], $locale);
    }
}

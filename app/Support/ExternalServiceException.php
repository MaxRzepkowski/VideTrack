<?php

namespace App\Support;

use RuntimeException;
use Throwable;

/**
 * A service outside the app did not give us what we asked for. Catch this
 * only where every reason means the same to the caller; otherwise catch the
 * child that matters — an outage, a refusal and a missing thing are three
 * different facts.
 *
 * The message is an i18n key, with $replace for its placeholders, and never
 * carries the request: whoever shows it asks for its wording() in the
 * reader's language. A provider of the catalogue that still words its
 * failure in plain English passes through wording() unchanged.
 */
abstract class ExternalServiceException extends RuntimeException
{
    /**
     * @param  array<string, string|int>  $replace
     */
    public function __construct(string $key, private readonly array $replace = [], ?Throwable $previous = null)
    {
        parent::__construct($key, 0, $previous);
    }

    /**
     * What report() logs beside the key: its placeholders (a status, a
     * path), which never hold the request's query.
     *
     * @return array<string, string|int>
     */
    public function context(): array
    {
        return $this->replace;
    }

    /** The i18n key of what happened, for whoever keeps it to word it later. */
    public function key(): string
    {
        return $this->getMessage();
    }

    /** What happened, in the reader's language (the app's when none is given). */
    public function wording(?string $locale = null): string
    {
        return __($this->key(), $this->replace, $locale);
    }
}

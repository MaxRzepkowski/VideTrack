<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * The library cannot take this change in the state it is in — a rule of the
 * domain, not a bug and not malformed input. The web and the API both answer
 * it as a validation error on the field the user acted through (one mapping
 * in bootstrap/app.php), so no controller catches it. The exception message
 * stays internal; `reason()` is what the user reads.
 */
abstract class LibraryRefusal extends RuntimeException
{
    abstract public function field(): string;

    abstract public function reason(): string;
}

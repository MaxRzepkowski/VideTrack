<?php

namespace App\Integrations\Connections;

use App\Models\User;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;
use LogicException;

/**
 * A form field holding an address a user gives, refused under the field when
 * the server may not call it for its owner ({@see UserAddresses}). This is
 * the early answer, not the protection: whatever calls the address checks it
 * again and holds the request to the approved IP.
 */
final readonly class ReachableAddress implements ValidationRule
{
    public function __construct(
        private UserAddresses $addresses,
        private User $owner,
    ) {}

    /**
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            throw new LogicException("The {$attribute} field is checked for an address only once it holds text.");
        }

        try {
            $this->addresses->pin(trim($value), $this->owner);
        } catch (UnusableAddress $unusable) {
            $fail($unusable->wording());
        }
    }
}

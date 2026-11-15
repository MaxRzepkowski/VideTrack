<?php

namespace App\Catalog\Sets;

use App\Catalog\Data\MediaSummary;
use App\Catalog\Department;

/**
 * One member of a set as its provider lists it: the title, and what the
 * set's subject did on it where the set is a person's work.
 */
final readonly class SetEntry
{
    public function __construct(
        public MediaSummary $media,
        public ?Department $department = null,
        public ?string $role = null,
        /** The person's place in the cast, the lead first. */
        public ?int $billing = null,
    ) {}
}

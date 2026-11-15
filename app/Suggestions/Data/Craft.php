<?php

namespace App\Suggestions\Data;

use App\Catalog\Department;

/**
 * Which of a person's credits count as "their films" on a shelf: directing
 * as the director (not an assistant), acting near the top of the cast.
 */
final readonly class Craft
{
    /**
     * @param  list<string>|null  $roles  the jobs that count, as the provider names them; null for any
     * @param  int|null  $maxBilling  places in the cast that count, from the lead; null for any
     */
    public function __construct(
        public Department $department,
        public ?array $roles = null,
        public ?int $maxBilling = null,
    ) {}
}

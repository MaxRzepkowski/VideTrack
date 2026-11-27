<?php

namespace App\Tasks\Attention\Data;

/**
 * The page the admin is reading the list on: the address it was opened at,
 * which some of what needs the admin is told against.
 */
final readonly class PageVisit
{
    public function __construct(public string $root) {}
}

<?php

namespace App\Tracking\Data;

use App\Catalog\Department;

/**
 * What a followed creator's page says now: the credits that may feed a
 * follower's calendar (the noise already filtered out), and every title it is
 * still credited on — the sweep has to tell "the date was pulled" from "the
 * credit is gone".
 */
final readonly class CreatorTitles
{
    /**
     * @param  list<CreatorTitle>  $credits
     * @param  array<string, true>  $creditedKeys  "type:ref" of every credit, unfiltered
     * @param  Department|null  $mainScope  the department a person is known for, where it has one
     */
    public function __construct(
        public string $name,
        public array $credits,
        public array $creditedKeys,
        public ?Department $mainScope = null,
    ) {}
}

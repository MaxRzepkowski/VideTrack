<?php

namespace App\Users\Statistics\Data;

use App\Catalog\Department;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** The people of one craft behind the period's titles, most titles first. */
#[TypeScript]
final readonly class CraftPeople
{
    /**
     * @param  list<string>  $types  the types whose titles credit anyone in the craft, in the registry's order
     * @param  list<PersonTally>  $people
     */
    public function __construct(
        public Department $department,
        public array $types,
        public array $people,
    ) {}
}

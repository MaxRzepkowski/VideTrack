<?php

namespace App\Tasks\CatalogRefresh;

use Carbon\CarbonInterface;

/**
 * What the providers' lists of changes said this morning: the roots they
 * named, the types they answered for in full, how far each list was read,
 * and the lists that could not be read.
 */
final readonly class ChangeFeedReading
{
    /**
     * @param  list<int>  $changedRootIds
     * @param  list<string>  $coveredTypes  types whose complete list answered: their roots need no asking unless named
     * @param  list<string>  $completeTypes  types some provider's list is complete for, answered today or not
     * @param  list<string>  $answeredTypes  types whose list answered today, complete or not
     * @param  list<array{provider: string, type: string, checked_until: CarbonInterface}>  $cursors
     * @param  list<string>  $errors
     */
    public function __construct(
        public array $changedRootIds = [],
        public array $coveredTypes = [],
        public array $completeTypes = [],
        public array $answeredTypes = [],
        public array $cursors = [],
        public array $errors = [],
    ) {}
}

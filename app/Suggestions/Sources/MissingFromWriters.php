<?php

namespace App\Suggestions\Sources;

use App\Catalog\Department;

/**
 * The unseen films of the screenwriters the user has seen most of.
 */
final readonly class MissingFromWriters extends MissingFromCrew
{
    public function title(): string
    {
        return __('suggestions.missing_from_writers');
    }

    public function description(): string
    {
        return __('suggestions.missing_from_writers_rule');
    }

    protected function department(): Department
    {
        return Department::Writing;
    }

    protected function bars(): string
    {
        return 'writers';
    }
}

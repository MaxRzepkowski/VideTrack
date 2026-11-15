<?php

namespace App\Suggestions\Sources;

use App\Catalog\Department;

/**
 * The unseen films of the directors the user has seen most of.
 */
final readonly class MissingFromDirectors extends MissingFromCrew
{
    public function title(): string
    {
        return __('suggestions.missing_from_directors');
    }

    public function description(): string
    {
        return __('suggestions.missing_from_directors_rule');
    }

    protected function department(): Department
    {
        return Department::Directing;
    }

    protected function bars(): string
    {
        return 'directors';
    }
}

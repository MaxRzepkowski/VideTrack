<?php

namespace App\Tracking\Data;

use App\Catalog\CompanyRole;
use App\Catalog\Data\MediaSummary;
use App\Catalog\Department;

/**
 * One title a creator is credited on, with the scope the credit falls in —
 * a person's department, a company's role — which a follower can narrow to.
 */
final readonly class CreatorTitle
{
    public function __construct(
        public MediaSummary $media,
        public Department|CompanyRole $scope,
    ) {}

    public function key(): string
    {
        return "{$this->media->type}:{$this->media->ref}";
    }
}

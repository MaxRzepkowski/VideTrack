<?php

namespace App\Catalog\Creators;

use App\Models\Company;
use App\Models\Person;
use App\Tasks\TaskName;
use LogicException;

/**
 * Where a followed creator's row lives: people in theirs, studios, publishers
 * and networks together in companies. The daily sweep runs per table.
 */
enum CreatorTable: string
{
    case People = 'people';
    case Companies = 'companies';

    public static function of(CreatorKind $kind): self
    {
        return match ($kind) {
            CreatorKind::Person => self::People,
            CreatorKind::Company, CreatorKind::Network => self::Companies,
            CreatorKind::Collection => throw new LogicException('A collection is a list, not a creator row.'),
        };
    }

    /** @return class-string<Person|Company> */
    public function model(): string
    {
        return match ($this) {
            self::People => Person::class,
            self::Companies => Company::class,
        };
    }

    /** The sweep's name in the task list. */
    public function sweepTask(): TaskName
    {
        return match ($this) {
            self::People => TaskName::RefreshFollowedPeople,
            self::Companies => TaskName::RefreshFollowedCompanies,
        };
    }
}

<?php

namespace App\Suggestions;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * The orders a suggestion shelf can be read in. A page offers the ones that
 * mean something for what it lists: subjects (collections) or titles.
 */
#[TypeScript]
enum ShelfSort: string
{
    /** The source's own order: what it judges most worth catching up on first. */
    case Suggested = 'suggested';

    /** A title's release; for a subject, the release of its latest missing title. */
    case Release = 'release';

    case Title = 'title';

    /** The provider's community rating of a title. */
    case Rating = 'rating';

    /** The day the user reached for the title, where the source knows one. */
    case Added = 'added';

    /** How many titles a subject still puts forward. */
    case Remaining = 'remaining';

    /** How far through a subject the user is. */
    case Progress = 'progress';

    /**
     * @return list<self>
     */
    public static function forSubjects(): array
    {
        return [self::Suggested, self::Remaining, self::Progress, self::Release, self::Title];
    }

    /**
     * @return list<self>
     */
    public static function forTitles(bool $withAdded): array
    {
        return [self::Suggested, self::Release, ...($withAdded ? [self::Added] : []), self::Rating, self::Title];
    }
}

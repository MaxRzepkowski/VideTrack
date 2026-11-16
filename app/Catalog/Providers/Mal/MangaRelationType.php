<?php

namespace App\Catalog\Providers\Mal;

use App\Catalog\Relations\TitleRelation;

/**
 * MAL's words (`relation_type`) for how another manga stands to this one,
 * those a reader can act on. A summary or the full story is the same story
 * told again, an adaptation and "other" say too little: they have no case,
 * so Payload::enum() reads them as no tie.
 */
enum MangaRelationType: string
{
    case Sequel = 'sequel';
    case Prequel = 'prequel';
    case SideStory = 'side_story';
    case SpinOff = 'spin_off';
    case ParentStory = 'parent_story';
    case AlternativeSetting = 'alternative_setting';
    case AlternativeVersion = 'alternative_version';

    /** The same tie in the catalog's words, from this manga's side. */
    public function relation(): TitleRelation
    {
        return match ($this) {
            self::Sequel => TitleRelation::Next,
            self::Prequel => TitleRelation::Previous,
            self::SideStory, self::SpinOff => TitleRelation::SpinOff,
            self::ParentStory => TitleRelation::SpinOffOf,
            self::AlternativeSetting, self::AlternativeVersion => TitleRelation::SameUniverse,
        };
    }
}

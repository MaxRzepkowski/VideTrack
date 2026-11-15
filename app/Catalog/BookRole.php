<?php

namespace App\Catalog;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * A part someone had in a book beside writing it, as a book credit carries
 * it in its role. A provider maps its own words onto these; a word it has
 * no case for travels as the provider wrote it.
 */
#[TypeScript]
enum BookRole: string
{
    case Translator = 'translator';
    case Illustrator = 'illustrator';
    case Narrator = 'narrator';
    case CoverArtist = 'cover_artist';
    case Editor = 'editor';
    case Foreword = 'foreword';
    case Afterword = 'afterword';
    case Adaptation = 'adaptation';
    case OriginalAuthor = 'original_author';

    public function department(): Department
    {
        return match ($this) {
            self::Translator => Department::Translation,
            self::Narrator => Department::Narration,
            self::Illustrator, self::CoverArtist => Department::Art,
            default => Department::Crew,
        };
    }
}

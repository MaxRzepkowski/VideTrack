<?php

namespace App\Integrations\Kavita;

use App\Catalog\MediaKind;

/**
 * The type a Kavita library is made with (`LibraryDto.type`), which tells
 * what its series are. Light novels are prose, and prose is a book here.
 */
enum KavitaLibraryType: int
{
    case Manga = 0;
    case Comic = 1;
    case Book = 2;
    case Image = 3;
    case LightNovel = 4;
    case ComicVine = 5;

    /** What a series of this library is here; none for loose images, which are no series to track. */
    public function kind(): ?MediaKind
    {
        return match ($this) {
            self::Manga => MediaKind::Manga,
            self::Comic, self::ComicVine => MediaKind::Comic,
            self::Book, self::LightNovel => MediaKind::Book,
            self::Image => null,
        };
    }
}

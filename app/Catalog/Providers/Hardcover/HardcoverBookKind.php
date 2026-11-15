<?php

namespace App\Catalog\Providers\Hardcover;

use App\Catalog\BookKind;
use App\Support\Payload;

/**
 * Hardcover's `book_category_id`: the form a book takes. The codes are
 * Hardcover's own; 1, a plain book, wears no badge.
 */
enum HardcoverBookKind: int
{
    case Book = 1;
    case Novella = 2;
    case ShortStory = 3;
    case GraphicNovel = 4;
    case FanFiction = 5;
    case Research = 6;
    case Poetry = 7;
    case Collection = 8;
    case WebNovel = 9;
    case LightNovel = 10;

    /**
     * The kind a books row names; null for a plain book and for a row that
     * does not say.
     *
     * @param  array<array-key, mixed>  $data
     */
    public static function of(array $data): ?BookKind
    {
        return Payload::intEnum($data, 'book_category_id', self::class)?->kind();
    }

    public function kind(): ?BookKind
    {
        return match ($this) {
            self::Book => null,
            self::Novella => BookKind::Novella,
            self::ShortStory => BookKind::ShortStory,
            self::GraphicNovel => BookKind::GraphicNovel,
            self::FanFiction => BookKind::FanFiction,
            self::Research => BookKind::Research,
            self::Poetry => BookKind::Poetry,
            self::Collection => BookKind::Anthology,
            self::WebNovel => BookKind::WebNovel,
            self::LightNovel => BookKind::LightNovel,
        };
    }
}

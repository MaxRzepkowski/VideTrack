<?php

namespace App\Catalog;

/**
 * What another service says a title is — a media server's "episode", an
 * export's "show". The values are our type codes, and the resolver uses them
 * so; the enum is the closed set a foreign record can name, while the
 * registry decides whether this instance serves the type at all.
 */
enum MediaKind: string
{
    case Movie = 'movie';
    case Show = 'show';
    case Season = 'season';
    case Episode = 'episode';
    case Game = 'game';
    case Book = 'book';
    case Manga = 'manga';
    case Comic = 'comic';
}

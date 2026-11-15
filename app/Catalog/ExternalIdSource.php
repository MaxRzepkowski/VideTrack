<?php

namespace App\Catalog;

/**
 * A foreign catalogue whose ids reach us (an import, a media server) and that
 * one of our providers can translate into its own ref, or the ISBN a book
 * carries everywhere. Our own providers' ids are no source of this kind: an
 * id we already speak is the ref itself.
 */
enum ExternalIdSource: string
{
    case Imdb = 'imdb';
    case Tvdb = 'tvdb';
    case Steam = 'steam';
    case Goodreads = 'goodreads';
    case Isbn = 'isbn';

    /**
     * A bag of ids narrowed to these sources: what a sender keys an item by,
     * less what is not asked about this way.
     *
     * @param  array<string, string>  $ids  source => id
     * @return array<string, string>
     */
    public static function only(array $ids, self ...$sources): array
    {
        return array_intersect_key($ids, array_flip(array_map(fn (self $source): string => $source->value, $sources)));
    }
}

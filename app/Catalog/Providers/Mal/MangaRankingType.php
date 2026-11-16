<?php

namespace App\Catalog\Providers\Mal;

/**
 * The rankings of MAL's manga catalogue the app browses, as MAL's
 * `ranking_type` names them: all of it by score, each comics format alone,
 * then by members and by favourites. Novels and doujinshi are not browsed
 * (prose, and the books module; niche).
 */
enum MangaRankingType: string
{
    case All = 'all';
    case Manga = 'manga';
    case Manhwa = 'manhwa';
    case Manhua = 'manhua';
    case OneShots = 'oneshots';
    case ByPopularity = 'bypopularity';
    case Favorite = 'favorite';

    /** The ranking's name in the request's language. */
    public function label(): string
    {
        return match ($this) {
            self::All => __('discover.highest_rated'),
            self::Manga => __('media.manga_format_manga'),
            self::Manhwa => __('media.manga_format_manhwa'),
            self::Manhua => __('media.manga_format_manhua'),
            self::OneShots => __('discover.one_shots'),
            self::ByPopularity => __('discover.most_popular'),
            self::Favorite => __('discover.most_favorited'),
        };
    }
}

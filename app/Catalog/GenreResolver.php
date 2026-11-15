<?php

namespace App\Catalog;

use App\Catalog\Data\GenreOption;
use App\Support\Locales;
use Closure;

/**
 * The genre a link names. Genre chips across the app link by display name,
 * and chips built from stored metadata carry whichever language wrote them,
 * so a name is matched in every supported locale — a German "Komödie" chip
 * still opens the comedy listing for an English reader.
 */
final readonly class GenreResolver
{
    /**
     * By ref first, then by name in the page's locale, then by name in each
     * other supported locale. The answer always carries the page-locale name.
     *
     * @param  list<GenreOption>  $genres  the dictionary in `$locale`
     * @param  Closure(string): list<GenreOption>  $genresIn  the same dictionary in another locale
     */
    public function resolve(string $requested, array $genres, string $locale, Closure $genresIn): ?GenreOption
    {
        foreach ($genres as $genre) {
            if ($genre->ref === $requested || strcasecmp($genre->name, $requested) === 0) {
                return $genre;
            }
        }

        foreach (Locales::supported() as $candidate) {
            if ($candidate === $locale) {
                continue;
            }

            foreach ($genresIn($candidate) as $genre) {
                if (strcasecmp($genre->name, $requested) === 0) {
                    return $this->inPageLocale($genre, $genres);
                }
            }
        }

        return null;
    }

    /**
     * @param  list<GenreOption>  $genres
     */
    private function inPageLocale(GenreOption $match, array $genres): GenreOption
    {
        foreach ($genres as $genre) {
            if ($genre->ref === $match->ref) {
                return $genre;
            }
        }

        return $match;
    }
}

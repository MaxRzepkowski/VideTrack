<?php

namespace App\Catalog\Providers\Tmdb;

use App\Catalog\Data\LocalizedTitle;
use App\Support\Locales;
use App\Support\Payload;

/**
 * A film's or show's name and poster in every UI language, read from the one
 * details payload: `translations` carries the name in each language, `images`
 * the posters of each language the request named. A twentieth language makes
 * the response longer, never adds a request.
 *
 * The name walks the language → the fallback language → the payload's own;
 * the original language's entry is often empty there, its name is the
 * original title. The poster walks TMDB's own order — the language, then
 * the original language — and then the fallback language, so a language
 * without a poster of its own reads like the fallback one rather than like
 * whichever locale the payload was fetched in.
 */
final readonly class TmdbLocalizedTitles
{
    /** The images a details request asks for: every UI language's, plus the untagged ones. */
    public function imageLanguages(): string
    {
        $codes = array_map(fn (string $locale): string => Locales::code($locale), Locales::supported());

        return implode(',', [...array_unique($codes), 'null']);
    }

    /**
     * @param  array<array-key, mixed>  $data  a details payload with `translations` and `images`
     * @param  string  $nameKey  `title` for a film, `name` for a show
     * @param  string  $originalKey  `original_title` / `original_name`
     * @return array<string, LocalizedTitle> locale => title
     */
    public function of(array $data, string $nameKey, string $originalKey): array
    {
        $original = Payload::string($data, 'original_language');
        $originalName = Payload::string($data, $originalKey);
        $names = $this->namesByLanguage($data, $nameKey);

        if ($original !== null && $originalName !== null) {
            $names[$original] ??= $originalName;
        }

        $posters = $this->postersByLanguage($data);
        $fallback = Locales::code(Locales::fallback());
        $localized = [];

        foreach (Locales::supported() as $locale) {
            $code = Locales::code($locale);
            $title = $names[$code] ?? $names[$fallback] ?? Payload::string($data, $nameKey);

            // Nameless in every language: the reader keeps the row's own.
            if ($title === null) {
                continue;
            }

            $localized[$locale] = new LocalizedTitle(
                title: $title,
                posterPath: $posters[$code]
                    ?? ($original === null ? null : $posters[$original] ?? null)
                    ?? $posters[$fallback]
                    ?? Payload::string($data, 'poster_path'),
            );
        }

        return $localized;
    }

    /**
     * The payload as a reader of `$locale` sees it: their name and poster at
     * the root, where every field built from it (the summary, the original
     * title beside it, a season's borrowed poster) reads them.
     *
     * @param  array<array-key, mixed>  $data
     * @param  array<string, LocalizedTitle>  $localized
     * @return array<array-key, mixed>
     */
    public function inLocale(array $data, array $localized, string $locale, string $nameKey): array
    {
        $title = $localized[$locale] ?? null;

        return $title === null ? $data : [...$data, $nameKey => $title->title, 'poster_path' => $title->posterPath];
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @return array<string, string> ISO 639-1 => name
     */
    private function namesByLanguage(array $data, string $nameKey): array
    {
        $names = [];

        foreach (Payload::list(data_get($data, 'translations.translations')) as $row) {
            $language = Payload::string($row, 'iso_639_1');
            $name = Payload::string(Payload::map($row['data'] ?? null), $nameKey);

            // A language arrives once per region (pt-BR, pt-PT); the first
            // regional variant with a name answers for the language.
            if ($language !== null && $name !== null && ! isset($names[$language])) {
                $names[$language] = $name;
            }
        }

        return $names;
    }

    /**
     * The top poster of each language — TMDB lists them best-rated first.
     *
     * @param  array<array-key, mixed>  $data
     * @return array<string, string> ISO 639-1 => file path
     */
    private function postersByLanguage(array $data): array
    {
        $posters = [];

        foreach (Payload::list(data_get($data, 'images.posters')) as $poster) {
            $language = Payload::string($poster, 'iso_639_1');
            $path = Payload::string($poster, 'file_path');

            if ($language !== null && $path !== null && ! isset($posters[$language])) {
                $posters[$language] = $path;
            }
        }

        return $posters;
    }
}

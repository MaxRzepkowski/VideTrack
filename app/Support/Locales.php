<?php

namespace App\Support;

/**
 * What a UI locale stands for outside the UI (`tracker.locales`). One map, so
 * a third language is one entry, not an edit in every class with its own
 * `match ('pl')`. An unknown locale reads as the default one.
 */
final class Locales
{
    /** The language tag providers are asked in, e.g. pl-PL. */
    public static function language(string $locale): string
    {
        return self::entry($locale)['language'];
    }

    /**
     * The locale as a page or a feed declares its language: a BCP 47 tag as
     * short as the locale is written (pl, pt-BR), the region only where it is
     * part of the language. Unlike {@see language()} it reads no map: the
     * locale it is given is one the UI already speaks.
     */
    public static function declaredLanguage(string $locale): string
    {
        return str_replace('_', '-', $locale);
    }

    /** The ISO 639-1 language alone, e.g. pl — what a provider tags a translation or an image with. */
    public static function code(string $locale): string
    {
        return strtolower(explode('-', self::language($locale))[0]);
    }

    /**
     * Every locale the UI speaks.
     *
     * @return list<string>
     */
    public static function supported(): array
    {
        /** @var list<string> */
        return config('tracker.supported_locales');
    }

    /**
     * Every UI language under its own name ("English", "Polski"), the way a
     * language picker lists them — a reader finds theirs whatever the page
     * is in.
     *
     * @return list<array{value: string, label: string}>
     */
    public static function choices(): array
    {
        return array_map(fn (string $locale): array => [
            'value' => $locale,
            // intl answers false only for a malformed tag; the code itself then names it.
            'label' => mb_convert_case(\Locale::getDisplayLanguage($locale, $locale) ?: $locale, MB_CASE_TITLE),
        ], self::supported());
    }

    /** The locale a reader falls back to when a text has no version in theirs. */
    public static function fallback(): string
    {
        return config()->string('app.fallback_locale');
    }

    /** The ISO 3166-1 country a viewer of this locale sees until they pick their own. */
    public static function region(string $locale): string
    {
        return self::entry($locale)['region'];
    }

    /**
     * Whether the language writes every noun with a capital, inside a sentence
     * too: a type's name keeps it there ("Filme"), elsewhere it goes lower case.
     */
    public static function capitalizesNouns(string $locale): bool
    {
        return self::entry($locale)['capitalized_nouns'];
    }

    /** @return array{language: string, region: string, capitalized_nouns: bool} */
    private static function entry(string $locale): array
    {
        /** @var array<string, array{language: string, region: string, capitalized_nouns: bool}> $locales */
        $locales = config('tracker.locales');

        return $locales[$locale] ?? $locales[config()->string('tracker.default_locale')] ?? $locales['en'];
    }
}

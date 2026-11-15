<?php

namespace App\Catalog\Providers\Hardcover;

use App\Catalog\Data\LocalizedTitle;
use App\Support\Locales;
use App\Support\Payload;

/**
 * A book in its readers' languages: the most read edition in each UI
 * language, asked for inside the book's own answer (one nested field per
 * language, so no request of its own). The fallback language reads the book
 * as Hardcover names it. An edition gives its title and cover; one without a
 * cover keeps the book's.
 */
final readonly class HardcoverEditions
{
    /** What the page reads of an edition in the reader's language. */
    private const FIELDS = 'title cached_image release_date release_year publisher { name } cached_contributors';

    public function __construct(private HardcoverPayload $payload) {}

    /** The variables the selection names, one language code each. */
    public function declarations(): string
    {
        return implode('', array_map(fn (int $index): string => ", \$language{$index}: String!", array_keys($this->languages())));
    }

    /** @return array<string, string> variable => ISO 639-1 code */
    public function variables(): array
    {
        $variables = [];

        foreach ($this->languages() as $index => $language) {
            $variables["language{$index}"] = $language['code'];
        }

        return $variables;
    }

    /** The selection inside `books_by_pk`: one aliased field per language. */
    public function selection(): string
    {
        $fields = [];

        foreach (array_keys($this->languages()) as $index) {
            $fields[] = "edition{$index}: editions(
                where: {language: {code2: {_eq: \$language{$index}}}, title: {_is_null: false}}
                order_by: {users_count: desc_nulls_last}
                limit: 1
            ) { ".self::FIELDS.' }';
        }

        return implode("\n", $fields);
    }

    /**
     * The edition each UI language reads, where Hardcover has one.
     *
     * @param  array<array-key, mixed>  $book
     * @return array<string, array<array-key, mixed>> locale => edition
     */
    public function byLocale(array $book): array
    {
        $editions = [];

        foreach ($this->languages() as $index => $language) {
            $edition = Payload::list($book["edition{$index}"] ?? null)[0] ?? null;

            if ($edition !== null && Payload::string($edition, 'title') !== null) {
                $editions[$language['locale']] = $edition;
            }
        }

        return $editions;
    }

    /**
     * The book as a reader of one edition sees it: the edition's title, and
     * its cover where it has one.
     *
     * @param  array<array-key, mixed>  $book
     * @param  array<array-key, mixed>  $edition
     * @return array<array-key, mixed>
     */
    public function asEdition(array $book, array $edition): array
    {
        return [
            ...$book,
            'title' => Payload::string($edition, 'title'),
            'cached_image' => $this->payload->coverUrl($edition) === null ? ($book['cached_image'] ?? null) : $edition['cached_image'],
        ];
    }

    /**
     * Every UI language's name and cover of the book: the fallback language's
     * is the book's own, so a row stored in another language still reads
     * right there.
     *
     * @param  array<array-key, mixed>  $book
     * @param  array<string, array<array-key, mixed>>  $editions  locale => edition
     * @return array<string, LocalizedTitle>
     */
    public function localizedTitles(array $book, array $editions): array
    {
        $titles = [];
        $own = Payload::string($book, 'title');

        if ($own !== null) {
            $titles[Locales::fallback()] = new LocalizedTitle($own, $this->payload->coverUrl($book));
        }

        foreach ($editions as $locale => $edition) {
            $shown = $this->asEdition($book, $edition);
            $titles[$locale] = new LocalizedTitle(Payload::text($shown, 'title'), $this->payload->coverUrl($shown));
        }

        return $titles;
    }

    /**
     * The UI languages but the fallback one, numbered as the query's aliases
     * and variables name them.
     *
     * @return list<array{locale: string, code: string}>
     */
    private function languages(): array
    {
        $languages = [];

        foreach (Locales::supported() as $locale) {
            if ($locale !== Locales::fallback()) {
                $languages[] = ['locale' => $locale, 'code' => Locales::code($locale)];
            }
        }

        return $languages;
    }
}

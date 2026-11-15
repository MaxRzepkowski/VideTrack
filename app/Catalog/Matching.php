<?php

namespace App\Catalog;

use App\Catalog\Data\MediaSummary;

/**
 * When two catalogue records name the same title or the same author. One
 * rule for every caller that places something by name — the import's title
 * matcher, the "based on the book" section — so a fix to it fixes both.
 */
final class Matching
{
    /**
     * "Resident Evil 7: Biohazard" and "Resident Evil 7 - biohazard" are one
     * title: case, spacing and punctuation do not count, letters and digits do.
     */
    public static function sameTitle(string $a, string $b): bool
    {
        $a = self::squashed($a);

        return $a !== '' && $a === self::squashed($b);
    }

    /**
     * Whether the record goes by the title: its own name or any other the
     * provider knows it by ("Bear and Breakfast" for "Bear & Breakfast").
     */
    public static function namesTitle(MediaSummary $record, string $title): bool
    {
        foreach ([$record->title, ...$record->alternativeTitles] as $name) {
            if (self::sameTitle($name, $title)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The same surname and, where both names give one, the same first
     * initial: "J. K. Rowling", "Joanne Rowling" and "Rowling" are one
     * author; "Stephen King" and "Owen King" are two.
     */
    public static function sameAuthor(string $a, string $b): bool
    {
        $a = self::words($a);
        $b = self::words($b);

        if ($a === [] || $b === [] || end($a) !== end($b)) {
            return false;
        }

        if (count($a) === 1 || count($b) === 1) {
            return true;
        }

        return mb_substr($a[0], 0, 1) === mb_substr($b[0], 0, 1);
    }

    /**
     * Whether the record credits any of the wanted authors.
     *
     * @param  list<string>  $wanted
     */
    public static function sharesAuthor(array $wanted, MediaSummary $record): bool
    {
        foreach ($record->authors as $name) {
            foreach ($wanted as $want) {
                if (self::sameAuthor($name, $want)) {
                    return true;
                }
            }
        }

        return false;
    }

    private static function squashed(string $text): string
    {
        return (string) preg_replace('/[^\p{L}\p{N}]+/u', '', mb_strtolower($text));
    }

    /** @return list<string> */
    private static function words(string $name): array
    {
        return preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($name), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }
}

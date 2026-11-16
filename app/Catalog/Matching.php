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
    /** A year or a platform a store adds in brackets at the end: "(2016)", "(PC)". */
    private const CLOSING_BRACKETS = '/\s*[(\[][^()\[\]]*[)\]]$/u';

    /** An edition named at the end, after a dash, a colon or a space. */
    private const EDITION = '/(?:\s*[-–—:]\s*|\s+)(?:(?:game of the year|goty|definitive|complete|enhanced|deluxe|ultimate|gold|special|anniversary|digital deluxe)(?:\s+edition)?|remastered|remaster|director\'?s cut|hd)$/iu';

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
     * Whether the record is the game the title names, once what stores and
     * launchers add to a game's name is set aside on both sides: an edition
     * ("Game of the Year Edition", "Definitive Edition"), a year or platform
     * in closing brackets, trademark signs, "&" for "and". A caller asks
     * this only after {@see namesTitle()} found nothing, so an edition the
     * provider knows by its own name still wins.
     */
    public static function namesGame(MediaSummary $record, string $title): bool
    {
        $wanted = self::gameBase($title);

        foreach ([$record->title, ...$record->alternativeTitles] as $name) {
            if (self::sameTitle(self::gameBase($name), $wanted)) {
                return true;
            }
        }

        return false;
    }

    /**
     * A game's name without the additions {@see namesGame()} sets aside:
     * "The Witcher® 3: Wild Hunt – Game of the Year Edition (PC)" is
     * "The Witcher 3: Wild Hunt". Additions can stack, so they come off one
     * by one from the end; a name made of nothing else stays as it is.
     */
    public static function gameBase(string $title): string
    {
        $base = trim(str_replace('&', ' and ', (string) preg_replace('/[™®©]/u', '', $title)));

        do {
            $before = $base;
            $base = trim((string) preg_replace([self::CLOSING_BRACKETS, self::EDITION], '', $base));
        } while ($base !== $before && $base !== '');

        return $base === '' ? trim($title) : (string) preg_replace('/\s+/u', ' ', $base);
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

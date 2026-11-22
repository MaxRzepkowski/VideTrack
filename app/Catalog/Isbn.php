<?php

namespace App\Catalog;

/**
 * A book's ISBN, in the one spelling the catalog asks about: thirteen digits.
 * Senders write either form, with hyphens and spaces; an ISBN-10 is the same
 * number as its 978-prefixed ISBN-13, with a check digit of its own.
 */
final class Isbn
{
    /** The ISBN-13 of an ISBN-10 or ISBN-13 however it is written; null when it is neither. */
    public static function thirteen(string $value): ?string
    {
        $chars = strtoupper((string) preg_replace('/[^0-9Xx]/', '', $value));

        if (preg_match('/^\d{13}$/', $chars) === 1) {
            return $chars;
        }

        if (preg_match('/^\d{9}[\dX]$/', $chars) !== 1) {
            return null;
        }

        $base = '978'.substr($chars, 0, 9);
        $sum = 0;

        foreach (str_split($base) as $index => $digit) {
            $sum += (int) $digit * ($index % 2 === 0 ? 1 : 3);
        }

        return $base.((10 - $sum % 10) % 10);
    }

    /** Whether an ISBN-13 was registered in the English-language groups (978-0, 978-1, 979-8). */
    public static function isEnglish(string $thirteen): bool
    {
        return str_starts_with($thirteen, '9780') || str_starts_with($thirteen, '9781') || str_starts_with($thirteen, '9798');
    }
}

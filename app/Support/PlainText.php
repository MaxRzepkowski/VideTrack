<?php

namespace App\Support;

/**
 * Plain text from the light HTML some providers put in descriptions and bios.
 * Tags go first and entities are decoded after, so an escaped "&lt;b&gt;" in
 * the source stays visible text instead of turning into markup.
 */
final class PlainText
{
    public static function fromHtml(mixed $html): ?string
    {
        if (! is_string($html)) {
            return null;
        }

        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = trim(str_replace("\u{00A0}", ' ', $text));

        return $text === '' ? null : $text;
    }
}

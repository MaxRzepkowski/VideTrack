<?php

namespace App\Integrations\Discord;

use App\Notifications\Channels\Data\ChannelDigest;
use App\Notifications\Channels\Data\DigestLine;

/**
 * The daily digest as Discord message text (no embed boxes): a header, one
 * emoji-headed section per kind of title, a closing line — split into
 * several messages only when Discord's content limit forces it.
 */
final readonly class DiscordDigestComposer
{
    /** Discord caps message content at 2000 chars — pack with headroom. */
    private const CONTENT_LIMIT = 1900;

    /** Ceiling for one section's lines, so a section splits before packing. */
    private const SECTION_LIMIT = 1700;

    /**
     * @return non-empty-list<string>
     */
    public function messages(ChannelDigest $digest): array
    {
        $blocks = [];

        foreach ($digest->sections as $section) {
            $label = '**'.$section->heading.'**';
            $heading = $section->emoji === null ? $label : "{$section->emoji} {$label}";

            foreach ($this->chunk(array_map($this->line(...), $section->lines)) as $index => $lines) {
                // An oversized section continues in the next block without
                // repeating its heading.
                $blocks[] = ($index === 0 ? $heading."\n" : '').$lines;
            }
        }

        $messages = [];
        $current = '📬 **'.$digest->heading.'**';

        foreach ($blocks as $block) {
            if (strlen($current) + strlen($block) + 2 > self::CONTENT_LIMIT) {
                $messages[] = $current;
                $current = '';
            }

            $current .= ($current === '' ? '' : "\n\n").$block;
        }

        $messages[] = $current."\n\n".$digest->closing;

        return $messages;
    }

    /**
     * One digest row: "• **subject**: label: meta", the subject linking to the
     * media page. `<>` around the URL keeps Discord from unfurling it into a
     * preview box.
     */
    private function line(DigestLine $line): string
    {
        $text = '• **['.$this->escapeMarkdown($line->subject).'](<'.$line->url.'>)**';

        if ($line->label !== '') {
            $text .= ': '.$line->label;
        }

        if ($line->meta !== null) {
            $text .= ': '.$this->escapeMarkdown($line->meta);
        }

        return $text;
    }

    /** Titles may carry Markdown-active characters; a stray `]` breaks the link. */
    private function escapeMarkdown(string $text): string
    {
        return addcslashes($text, '\\*_~`[]');
    }

    /**
     * @param  list<string>  $lines
     * @return list<string> line runs, each within one message's budget
     */
    private function chunk(array $lines): array
    {
        $chunks = [];
        $current = '';

        foreach ($lines as $line) {
            if ($current !== '' && strlen($current) + strlen($line) + 1 > self::SECTION_LIMIT) {
                $chunks[] = $current;
                $current = '';
            }

            $current .= ($current === '' ? '' : "\n").$line;
        }

        if ($current !== '') {
            $chunks[] = $current;
        }

        return $chunks;
    }
}

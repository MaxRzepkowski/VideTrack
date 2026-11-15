<?php

namespace App\Notifications;

use App\Catalog\MediaTypeRegistry;
use App\Support\Payload;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Collection;

/**
 * The daily Discord digest as plain text messages (no embed boxes): a header,
 * one emoji-headed section per kind of title, a closing line — split into
 * several messages only when Discord's content limit forces it. A section is a
 * root media type, in the registry's order, named by the type's own
 * registration; a season or an episode goes under its show.
 */
final readonly class DiscordDigestComposer
{
    /** Discord caps message content at 2000 chars — pack with headroom. */
    private const CONTENT_LIMIT = 1900;

    /** Ceiling for one section's lines, so a section splits before packing. */
    private const SECTION_LIMIT = 1700;

    /** The section for anything no registered type names. */
    private const OTHER = '';

    public function __construct(
        private NotificationCopy $copy,
        private MediaTypeRegistry $types,
    ) {}

    /**
     * @param  Collection<int, DatabaseNotification>  $notifications
     * @return list<string>
     */
    public function messages(Collection $notifications, string $locale, string $timezone): array
    {
        $sections = [];

        foreach ($notifications as $notification) {
            $event = MediaEventPayload::fromStored($notification->data);
            $sections[$this->sectionOf(Payload::string($event->media, 'type'))][] = $this->line($event, $locale, $timezone);
        }

        $blocks = [];

        foreach ($this->headings($locale) as $section => $heading) {
            foreach ($this->chunk($sections[$section] ?? []) as $index => $lines) {
                // An oversized section continues in the next block without
                // repeating its heading.
                $blocks[] = ($index === 0 ? $heading."\n" : '').$lines;
            }
        }

        $messages = [];
        $current = '📬 **'.__('notifications.your_daily_digest', [], $locale).'**';

        foreach ($blocks as $block) {
            if (strlen($current) + strlen($block) + 2 > self::CONTENT_LIMIT) {
                $messages[] = $current;
                $current = '';
            }

            $current .= ($current === '' ? '' : "\n\n").$block;
        }

        $messages[] = $current."\n\n".__('notifications.enjoy_your_media', [], $locale);

        return $messages;
    }

    /** The root type a title's notification files under, or the catch-all section. */
    private function sectionOf(?string $type): string
    {
        if ($type === null || ! $this->types->has($type)) {
            return self::OTHER;
        }

        $lineage = $this->types->lineage($type);
        $root = end($lineage);

        return $root->parentType === null && $root->pluralLabel !== null ? $root->code : self::OTHER;
    }

    /**
     * Every section's heading, in the order the digest lists them.
     *
     * @return array<string, string> section => heading
     */
    private function headings(string $locale): array
    {
        $headings = [];

        foreach ($this->types->all() as $definition) {
            if ($definition->parentType === null && $definition->pluralLabel !== null) {
                $label = '**'.__($definition->pluralLabel, [], $locale).'**';
                $headings[$definition->code] = $definition->emoji === null ? $label : "{$definition->emoji} {$label}";
            }
        }

        $headings[self::OTHER] = '✨ **'.__('notifications.other_titles', [], $locale).'**';

        return $headings;
    }

    /**
     * One digest row: "• **subject**: label: meta", the subject linking to the
     * media page. `<>` around the URL keeps Discord from unfurling it into a
     * preview box.
     */
    private function line(MediaEventPayload $event, string $locale, string $timezone): string
    {
        $parts = $this->copy->digestParts($event, $locale, $timezone);

        $line = '• **['.$this->escapeMarkdown($parts['subject']).'](<'.$this->copy->url($event).'>)**';

        if ($parts['label'] !== '') {
            $line .= ': '.$parts['label'];
        }

        if ($parts['meta'] !== null && $parts['meta'] !== '') {
            $line .= ': '.$this->escapeMarkdown($parts['meta']);
        }

        return $line;
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

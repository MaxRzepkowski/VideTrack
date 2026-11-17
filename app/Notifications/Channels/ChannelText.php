<?php

namespace App\Notifications\Channels;

use App\Notifications\Channels\Data\ChannelDigest;
use App\Notifications\Channels\Data\ChannelMessage;
use App\Notifications\Channels\Data\DigestLine;

/**
 * A message and a digest as plain text, for the channels that read nothing
 * else (an e-mail, most of what Apprise delivers to): the headline goes in
 * the title or subject, this is the body.
 */
final readonly class ChannelText
{
    public function message(ChannelMessage $message): string
    {
        return $message->detail === null ? $message->url : "{$message->detail}\n{$message->url}";
    }

    public function digest(ChannelDigest $digest): string
    {
        $blocks = [];

        foreach ($digest->sections as $section) {
            $heading = $section->emoji === null ? $section->heading : "{$section->emoji} {$section->heading}";
            $blocks[] = $heading."\n".implode("\n", array_map($this->line(...), $section->lines));
        }

        $blocks[] = $digest->closing;

        return implode("\n\n", $blocks);
    }

    /** "• subject: label: meta", the link on the line under it. */
    private function line(DigestLine $line): string
    {
        $text = '• '.$line->subject;

        if ($line->label !== '') {
            $text .= ': '.$line->label;
        }

        if ($line->meta !== null) {
            $text .= ': '.$line->meta;
        }

        return $text."\n  ".$line->url;
    }
}

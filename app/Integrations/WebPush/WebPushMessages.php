<?php

namespace App\Integrations\WebPush;

use App\Integrations\WebPush\Data\WebPushMessage;
use App\Notifications\Channels\ChannelText;
use App\Notifications\Channels\Data\ChannelDigest;
use App\Notifications\Channels\Data\ChannelMessage;
use App\Support\PublicUrl;
use Illuminate\Support\Carbon;

/**
 * A notification and a day's digest as the device shows them: a headline,
 * a few lines under it, a picture, and the page a click opens.
 */
final readonly class WebPushMessages
{
    /** The app's own icon, for a message without a cover. */
    public const ICON = 'pwa-192x192.png';

    /** Lines of a digest a notification holds; a phone folds away what follows anyway. */
    public const DIGEST_LINES = 8;

    public function __construct(private ChannelText $text) {}

    public function message(ChannelMessage $message): WebPushMessage
    {
        return new WebPushMessage(
            title: $message->title,
            body: $message->detail,
            url: $message->url,
            icon: $message->imageUrl ?? PublicUrl::to(self::ICON),
            timestamp: Carbon::parse($message->writtenAt)->getTimestampMs(),
        );
    }

    /** The day in one notification, its lines without links: a click opens the notifications page. */
    public function digest(ChannelDigest $digest): WebPushMessage
    {
        $lines = [];

        foreach ($digest->sections as $section) {
            foreach ($section->lines as $line) {
                $lines[] = $this->text->digestLine($line);
            }
        }

        if (count($lines) > self::DIGEST_LINES) {
            $lines = [...array_slice($lines, 0, self::DIGEST_LINES), '…'];
        }

        return new WebPushMessage(
            title: $digest->heading,
            body: implode("\n", $lines),
            url: PublicUrl::to(route('notifications.index', absolute: false)),
            icon: PublicUrl::to(self::ICON),
            timestamp: now()->getTimestampMs(),
        );
    }
}

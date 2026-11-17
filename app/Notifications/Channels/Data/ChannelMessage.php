<?php

namespace App\Notifications\Channels\Data;

/**
 * One notification as every channel receives it: worded by NotificationCopy
 * in the recipient's language and timezone, so a driver only lays it out.
 */
final readonly class ChannelMessage
{
    public function __construct(
        public string $title,
        /** The facts under the headline, with the air time when there is one. */
        public ?string $detail,
        /** The instance's page the message leads to. */
        public string $url,
        public ?string $imageUrl,
        /** The title type's colour, as an RGB int. */
        public int $color,
        /** When the notification was written (ISO 8601), not when a retry delivered it. */
        public string $writtenAt,
    ) {}
}

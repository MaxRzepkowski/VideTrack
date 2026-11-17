<?php

namespace App\Notifications\Channels;

/** How the service behind a channel took a message. */
enum DeliveryAnswer: string
{
    case Delivered = 'delivered';

    /** The address leads nowhere: deleted at the service, or mistyped. */
    case Gone = 'gone';

    /** The service answered and refused the message. */
    case Refused = 'refused';

    /** The service took too many at once; a moment later it takes them. */
    case Throttled = 'throttled';

    /** The service could not be reached or failed itself; worth another try later. */
    case Unavailable = 'unavailable';

    /** Whether the same message may go through later, unchanged. */
    public function isTemporary(): bool
    {
        return $this === self::Throttled || $this === self::Unavailable;
    }

    /** What a channel remembers of this answer: the failure, or nothing once a message arrived. */
    public function failure(): ?self
    {
        return $this === self::Delivered ? null : $this;
    }
}

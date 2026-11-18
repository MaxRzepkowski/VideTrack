<?php

namespace App\Support\Delivery;

/** How the receiver of a message took it. */
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

    /** How a message failed, in the reader's language; null for one that arrived. */
    public function failureWording(): ?string
    {
        $answer = $this;

        if ($answer === self::Delivered) {
            return null;
        }

        return __(match ($answer) {
            self::Gone => 'settings.delivery_failure_gone',
            self::Refused => 'settings.delivery_failure_refused',
            self::Throttled => 'settings.delivery_failure_throttled',
            self::Unavailable => 'settings.delivery_failure_unavailable',
        });
    }
}

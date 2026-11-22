<?php

namespace App\Notifications\Events;

use App\Notifications\Data\EventLine;
use App\Notifications\MediaEventPayload;
use App\Notifications\NotificationsServiceProvider;

/**
 * One kind of media event: the class that builds it (a static factory, which
 * keys it so it happens once) also writes what it says, in the recipient's
 * language. A new kind of notification is a class here and a line in
 * {@see NotificationsServiceProvider}.
 */
interface EventKind
{
    /** The headline. */
    public function title(MediaEventPayload $event, string $locale): string;

    /**
     * The facts under the headline, each its own part: a screen sets them
     * apart with a hairline, plain text joins them.
     *
     * @return list<string>
     */
    public function detail(MediaEventPayload $event, string $locale): array;

    /**
     * The event as a line of a list, in parts: the title it is about, the
     * event spelled out ("New episode", "Out today"…), the facts it adds
     * (episode code, platform…) and the value it moved. Each line says what it
     * means on its own, as a digest reads.
     */
    public function line(MediaEventPayload $event, string $locale): EventLine;
}

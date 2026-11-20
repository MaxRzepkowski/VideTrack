<?php

namespace App\Notifications\Events;

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
     * One digest line in parts: `subject` (the linked title), `label` (the
     * event spelled out: "New episode", "Out today"…) and `meta` (episode
     * code with air time, moved dates, platform…). Each row says what it
     * means, unlike the bell, where the icon and grouping carry that.
     *
     * @return array{subject: string, label: string, meta: string|null}
     */
    public function digest(MediaEventPayload $event, string $locale, string $timezone): array;
}

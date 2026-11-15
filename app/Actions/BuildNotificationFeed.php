<?php

namespace App\Actions;

use App\Models\User;
use App\Notifications\Data\NotificationFeed;
use App\Notifications\NotificationCopy;
use Illuminate\Notifications\DatabaseNotification;

/**
 * The header bell's payload: the unread counter plus the newest few entries.
 *
 * Read from two places that must not drift apart — the shared Inertia prop
 * (every page render) and the endpoint an open tab polls, since a
 * notification written by the queue reaches the browser only when somebody
 * asks for it. The full feed lives on /notifications.
 */
final readonly class BuildNotificationFeed
{
    public function __construct(private NotificationCopy $copy) {}

    /** Enough to scroll through in the dropdown without paginating it. */
    public const LIMIT = 15;

    public function execute(User $user, string $locale): NotificationFeed
    {
        return new NotificationFeed(
            unread: $user->unreadNotifications()->count(),
            // The same entry the notifications page lists.
            recent: array_values($user->notifications()->limit(self::LIMIT)->get()
                ->map(fn (DatabaseNotification $notification) => $this->copy->entry($notification, $locale))
                ->all()),
        );
    }
}

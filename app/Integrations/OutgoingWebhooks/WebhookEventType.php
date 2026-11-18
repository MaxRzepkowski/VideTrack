<?php

namespace App\Integrations\OutgoingWebhooks;

/** What a webhook message is about: the `type` a receiver branches on, a public contract. */
enum WebhookEventType: string
{
    case PlayRecorded = 'play.recorded';
    case PlayRemoved = 'play.removed';
    case ProgressLogged = 'progress.logged';
    case RewatchStarted = 'rewatch.started';
    case RatingChanged = 'rating.changed';
    case RatingRemoved = 'rating.removed';
    case FavoriteChanged = 'favorite.changed';
    case StatusChanged = 'status.changed';
    case ListItemAdded = 'list.item_added';
    case ListItemRemoved = 'list.item_removed';

    /** A notification, the moment the bell gets it. */
    case NotificationCreated = 'notification.created';

    /** The settings page's test. */
    case Test = 'webhook.test';

    /** The group a receiver ticks to hear of it; null for the test, which goes whatever is ticked. */
    public function group(): ?WebhookEventGroup
    {
        return match ($this) {
            self::PlayRecorded, self::PlayRemoved, self::ProgressLogged, self::RewatchStarted => WebhookEventGroup::Activity,
            self::RatingChanged, self::RatingRemoved => WebhookEventGroup::Ratings,
            self::FavoriteChanged => WebhookEventGroup::Favorites,
            self::StatusChanged => WebhookEventGroup::Status,
            self::ListItemAdded, self::ListItemRemoved => WebhookEventGroup::Lists,
            self::NotificationCreated => WebhookEventGroup::Notifications,
            self::Test => null,
        };
    }
}

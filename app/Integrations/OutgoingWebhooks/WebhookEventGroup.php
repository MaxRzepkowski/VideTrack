<?php

namespace App\Integrations\OutgoingWebhooks;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** What a webhook can be told about, as the user ticks it: each group stands for a few events. */
#[TypeScript]
enum WebhookEventGroup: string
{
    case Activity = 'activity';
    case Ratings = 'ratings';
    case Favorites = 'favorites';
    case Status = 'status';
    case Lists = 'lists';
    case Notifications = 'notifications';

    public function label(): string
    {
        return __(match ($this) {
            self::Activity => 'settings.webhook_events_activity',
            self::Ratings => 'settings.webhook_events_ratings',
            self::Favorites => 'settings.webhook_events_favorites',
            self::Status => 'settings.webhook_events_status',
            self::Lists => 'settings.webhook_events_lists',
            self::Notifications => 'settings.webhook_events_notifications',
        });
    }

    public function description(): string
    {
        return __(match ($this) {
            self::Activity => 'settings.webhook_events_activity_help',
            self::Ratings => 'settings.webhook_events_ratings_help',
            self::Favorites => 'settings.webhook_events_favorites_help',
            self::Status => 'settings.webhook_events_status_help',
            self::Lists => 'settings.webhook_events_lists_help',
            self::Notifications => 'settings.webhook_events_notifications_help',
        });
    }
}

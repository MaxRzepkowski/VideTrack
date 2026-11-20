<?php

namespace App\Notifications\Events;

use App\Catalog\MediaItemSummaries;
use App\Models\MediaItem;
use App\Notifications\MediaEventPayload;
use App\Notifications\MediaEventType;

/** A show's next season announced. */
final readonly class SeasonAnnounced implements EventKind
{
    public static function of(MediaItem $root, int $season): MediaEventPayload
    {
        return new MediaEventPayload(
            type: MediaEventType::SeasonAnnounced,
            key: "season_announced:{$root->type}:{$root->provider_ref}:{$season}",
            media: MediaItemSummaries::summary($root)->jsonSerialize(),
            season: $season,
        );
    }

    public function title(MediaEventPayload $event, string $locale): string
    {
        return __('notifications.new_season_of_title_announced', ['title' => $event->title()], $locale);
    }

    public function detail(MediaEventPayload $event, string $locale): array
    {
        return [__('common.season_number', ['number' => (string) $event->season], $locale)];
    }

    public function digest(MediaEventPayload $event, string $locale, string $timezone): array
    {
        return [
            'subject' => $event->title(),
            'label' => __('notifications.season_number_announced', ['number' => (string) $event->season], $locale),
            'meta' => null,
        ];
    }
}

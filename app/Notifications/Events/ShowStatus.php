<?php

namespace App\Notifications\Events;

use App\Catalog\MediaItemSummaries;
use App\Catalog\TitleStatus;
use App\Models\MediaItem;
use App\Notifications\Copy\CopyFormat;
use App\Notifications\Data\EventLine;
use App\Notifications\Data\ValueChange;
use App\Notifications\MediaEventPayload;
use App\Notifications\MediaEventType;

/** A show's status changed: renewed, ended, cancelled. */
final readonly class ShowStatus implements EventKind
{
    public function __construct(private CopyFormat $format) {}

    public static function of(MediaItem $root, string $status, string $oldStatus): MediaEventPayload
    {
        $slug = str($status)->slug()->value();

        return new MediaEventPayload(
            type: MediaEventType::ShowStatus,
            key: "show_status:{$root->type}:{$root->provider_ref}:{$slug}",
            media: MediaItemSummaries::summary($root)->jsonSerialize(),
            status: $status,
            oldStatus: $oldStatus,
        );
    }

    public function title(MediaEventPayload $event, string $locale): string
    {
        return __('notifications.title_changed_status', ['title' => $event->title()], $locale);
    }

    public function detail(MediaEventPayload $event, string $locale): array
    {
        return $this->format->change($this->change($event, $locale));
    }

    public function line(MediaEventPayload $event, string $locale): EventLine
    {
        return new EventLine(subject: $event->title(), label: __('notifications.status_change', [], $locale), change: $this->change($event, $locale));
    }

    /** The status it moved to, from the one before when there was one. */
    private function change(MediaEventPayload $event, string $locale): ?ValueChange
    {
        $status = TitleStatus::tryFrom((string) $event->status);

        if ($status === null) {
            return null;
        }

        return new ValueChange(from: TitleStatus::tryFrom((string) $event->oldStatus)?->label($locale), to: $status->label($locale));
    }
}

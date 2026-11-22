<?php

namespace App\Notifications\Events;

use App\Catalog\Data\MediaSummary;
use App\Catalog\Data\RelatedRelease;
use App\Catalog\DatePrecision;
use App\Catalog\MediaItemSummaries;
use App\Models\MediaItem;
use App\Notifications\Copy\CopyFormat;
use App\Notifications\Data\EventLine;
use App\Notifications\Data\ValueChange;
use App\Notifications\MediaEventPayload;
use App\Notifications\MediaEventType;
use InvalidArgumentException;

/** A release date first announced or moved, still ahead — or only sharpened: "2028" becoming 12 March 2028 is news about the day. */
final readonly class DateChanged implements EventKind
{
    public function __construct(private CopyFormat $format) {}

    /** The title's date, as `$root` now holds it, moved from the one before. */
    public static function of(MediaItem $root, ?string $oldDate, DatePrecision $oldPrecision): MediaEventPayload
    {
        $date = $root->release_date?->toDateString() ?? throw new InvalidArgumentException('A moved date names the new one.');

        return self::moved(MediaItemSummaries::summary($root), $date, $root->release_date_precision, $oldDate, $oldPrecision);
    }

    /**
     * The date of a tracked game's DLC, remake or remaster moved. Keyed as
     * the release's own move, so a user who also tracks it directly hears of
     * it once.
     */
    public static function ofRelated(MediaItem $game, RelatedRelease $release, string $oldDate, DatePrecision $oldPrecision): MediaEventPayload
    {
        return self::moved(MediaItemSummaries::related($game, $release), $release->date, $release->precision, $oldDate, $oldPrecision);
    }

    public function title(MediaEventPayload $event, string $locale): string
    {
        return __('notifications.new_release_date_for_title', ['title' => $event->title()], $locale);
    }

    public function detail(MediaEventPayload $event, string $locale): array
    {
        return [...$this->format->relatedFacts($event, $locale), ...$this->format->change($this->change($event, $locale))];
    }

    /** The day it moved to, from the one before when there was one; null when the new date says nothing. */
    private function change(MediaEventPayload $event, string $locale): ?ValueChange
    {
        $next = $this->format->date($event->date, $event->datePrecision(), $locale);

        if ($next === null) {
            return null;
        }

        return new ValueChange(from: $this->format->date($event->oldDate, $event->oldDatePrecision ?? DatePrecision::Day, $locale), to: $next);
    }

    public function line(MediaEventPayload $event, string $locale): EventLine
    {
        return new EventLine(
            subject: $event->title(),
            label: __('notifications.new_release_date', [], $locale),
            facts: $this->format->relatedFacts($event, $locale),
            change: $this->change($event, $locale),
        );
    }

    private static function moved(MediaSummary $card, string $date, DatePrecision $precision, ?string $oldDate, DatePrecision $oldPrecision): MediaEventPayload
    {
        return new MediaEventPayload(
            type: MediaEventType::DateChanged,
            // An exact day keeps the plain key.
            key: "date_changed:{$card->type}:{$card->ref}:{$date}".($precision === DatePrecision::Day ? '' : ":{$precision->value}"),
            media: $card->jsonSerialize(),
            date: $date,
            oldDate: $oldDate,
            oldDatePrecision: $oldDate === null || $oldPrecision === DatePrecision::Day ? null : $oldPrecision,
        );
    }
}

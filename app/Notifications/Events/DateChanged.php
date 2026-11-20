<?php

namespace App\Notifications\Events;

use App\Catalog\Data\MediaSummary;
use App\Catalog\Data\RelatedRelease;
use App\Catalog\DatePrecision;
use App\Catalog\MediaItemSummaries;
use App\Models\MediaItem;
use App\Notifications\Copy\CopyFormat;
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
        $related = $this->format->relatedFacts($event, $locale);
        $next = $this->format->date($event->date, $event->datePrecision(), $locale);

        if ($next === null) {
            return $related;
        }

        $previous = $this->format->date($event->oldDate, $event->oldDatePrecision ?? DatePrecision::Day, $locale);

        // An arrow, not a separator: a date MOVED is one fact.
        return [...$related, $previous !== null ? "{$previous} → {$next}" : $next];
    }

    public function digest(MediaEventPayload $event, string $locale, string $timezone): array
    {
        return ['subject' => $event->title(), 'label' => __('notifications.new_release_date', [], $locale), 'meta' => $this->format->plain($this->detail($event, $locale))];
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

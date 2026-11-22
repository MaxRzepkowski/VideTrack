<?php

namespace App\Notifications\Copy;

use App\Catalog\DatePrecision;
use App\Notifications\Data\ValueChange;
use App\Notifications\MediaEventPayload;
use App\Support\Payload;
use Illuminate\Support\Carbon;

/**
 * The pieces of a notification's words every kind of event writes alike: a
 * date as far as it is known, the first few of a list of titles, the facts as
 * one line of plain text, a moved value as "old → new", the hour an episode
 * airs, and what a game's DLC or remake is and whose.
 */
final readonly class CopyFormat
{
    /** How many titles a notification names before the rest is left to its page. */
    private const PREVIEW_TITLES = 3;

    /** A channel carries plain text, so the facts a screen sets apart with a hairline are joined by a dash. */
    private const PLAIN_SEPARATOR = ' – ';

    /**
     * A moved value as a fact of plain text: "old → new", or the new one alone
     * when there was none before. An arrow, not a separator: a value moved is
     * one fact.
     *
     * @return list<string>
     */
    public function change(?ValueChange $change): array
    {
        if ($change === null) {
            return [];
        }

        return [$change->from === null ? $change->to : "{$change->from} → {$change->to}"];
    }

    /**
     * The event's date as its one fact, as far as it is known; none when it
     * has no date.
     *
     * @return list<string>
     */
    public function dateFact(MediaEventPayload $event, string $locale): array
    {
        $date = $this->date($event->date, $event->datePrecision(), $locale);

        return $date === null ? [] : [$date];
    }

    /**
     * The facts as one line of plain text; null when there are none.
     *
     * @param  list<string>  $facts
     */
    public function plain(array $facts): ?string
    {
        return $facts === [] ? null : implode(self::PLAIN_SEPARATOR, $facts);
    }

    /**
     * The facts as plain text with the air time after it, when the position has one.
     *
     * @param  list<string>  $facts
     */
    public function timed(array $facts, MediaEventPayload $event, string $locale, string $timezone): ?string
    {
        $detail = $this->plain($facts);
        $time = $this->airTime($event, $locale, $timezone);

        if ($time === null) {
            return $detail;
        }

        return $detail === null
            ? __('notifications.at_time', ['time' => $time], $locale)
            : __('notifications.detail_at_time', ['detail' => $detail, 'time' => $time], $locale);
    }

    /**
     * Localized broadcast time-of-day in the viewer's timezone ("14:00" /
     * "2:00 PM"), when the position carries a real airing instant.
     */
    public function airTime(MediaEventPayload $event, string $locale, string $timezone): ?string
    {
        $instant = Payload::string($event->media, 'releaseTime');

        if ($instant === null) {
            return null;
        }

        return Carbon::parse($instant)->setTimezone($timezone)->settings(['locale' => $locale])->isoFormat('LT');
    }

    /**
     * What a tracked game's DLC or remake is and whose, first among its
     * facts: its title alone says neither.
     *
     * @return list<string>
     */
    public function relatedFacts(MediaEventPayload $event, string $locale): array
    {
        return $event->relatedCard()?->facts($locale) ?? [];
    }

    /**
     * The titles a followed creator or collection brought: the first few of
     * several, or the one with its date.
     *
     * @return list<string>
     */
    public function newTitles(MediaEventPayload $event, string $locale): array
    {
        if ($event->listsSeveral()) {
            return $this->titlesPreview($event, $locale);
        }

        return [$event->title(), ...$this->dateFact($event, $locale)];
    }

    /**
     * The first few titles, then how many more the notification's page lists.
     *
     * @return list<string>
     */
    public function titlesPreview(MediaEventPayload $event, string $locale): array
    {
        $titles = $event->titles();
        $named = array_slice($titles, 0, self::PREVIEW_TITLES);
        $rest = count($titles) - count($named);

        return $rest > 0 ? [...$named, trans_choice('notifications.count_more', $rest, [], $locale)] : $named;
    }

    /**
     * Long localized date, as the client's `formatReleaseDate` writes it: the
     * day when it is known, else only as much as is ("March 2028", "2028").
     */
    public function date(?string $date, DatePrecision $precision, string $locale): ?string
    {
        if ($date === null) {
            return null;
        }

        $day = Carbon::parse($date)->settings(['locale' => $locale]);

        return match ($precision) {
            DatePrecision::Day => $day->isoFormat('LL'),
            DatePrecision::Month => $day->isoFormat('MMMM YYYY'),
            DatePrecision::Quarter => __('common.date_quarter', ['quarter' => (string) $day->quarter, 'year' => (string) $day->year], $locale),
            DatePrecision::Year => (string) $day->year,
        };
    }
}

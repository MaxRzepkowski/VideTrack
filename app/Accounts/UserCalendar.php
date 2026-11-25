<?php

namespace App\Accounts;

use App\Models\User;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;

/**
 * The user's own calendar, not Greenwich's: the day a moment falls on for
 * them. A film finished at half past midnight in Warsaw is that day's,
 * though UTC still calls it the evening before.
 */
final readonly class UserCalendar
{
    private DateTimeZone $zone;

    public function __construct(string $timezone)
    {
        $this->zone = new DateTimeZone($timezone);
    }

    public static function of(User $user): self
    {
        return new self($user->preferences->effectiveTimezone());
    }

    /** A moment as the user's clock showed it: their hour, their weekday. */
    public function moment(DateTimeInterface $moment): CarbonImmutable
    {
        return CarbonImmutable::instance($moment)->setTimezone($this->zone);
    }

    /**
     * The calendar day (Y-m-d) a moment fell on for the user; null for no moment.
     *
     * @return ($moment is null ? null : string)
     */
    public function day(?DateTimeInterface $moment): ?string
    {
        return $moment === null ? null : $this->moment($moment)->toDateString();
    }

    /**
     * The user's calendar day (Y-m-d) of a timestamp as the database holds it,
     * read on the instance's clock. A panel asks this of every row it groups,
     * so it stays on PHP's own dates: a Carbon object per row costs several
     * times more.
     */
    public function dayOfStored(string $stamp): string
    {
        return (new DateTimeImmutable($stamp))->setTimezone($this->zone)->format('Y-m-d');
    }

    /**
     * The day it is for the user now, at its start: past midnight in Warsaw it
     * is already tomorrow there, though the server's clock still says today.
     */
    public function today(): CarbonImmutable
    {
        return CarbonImmutable::now($this->zone)->startOfDay();
    }

    /** The moment a calendar day (Y-m-d) begins for the user. */
    public function startOf(string $day): CarbonImmutable
    {
        return $this->onDay($day)->startOfDay();
    }

    /** The last moment of a calendar day (Y-m-d) for the user. */
    public function endOf(string $day): CarbonImmutable
    {
        return $this->onDay($day)->endOfDay();
    }

    /**
     * The first day of the week a date falls in, for a week that opens on the
     * given weekday (ISO: 1 is Monday, 7 Sunday; see UserPreferences::firstWeekday).
     */
    public static function weekStart(CarbonImmutable $date, int $firstWeekday): CarbonImmutable
    {
        return $date->subDays(($date->dayOfWeekIso - $firstWeekday + 7) % 7);
    }

    private function onDay(string $day): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('!Y-m-d', $day, $this->zone) ?: throw new InvalidArgumentException("Not a day: [{$day}].");
    }
}

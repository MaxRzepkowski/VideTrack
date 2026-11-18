<?php

namespace App\Accounts;

use App\Models\User;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use InvalidArgumentException;

/**
 * The user's own calendar, not Greenwich's: the day a moment falls on for
 * them. A film finished at half past midnight in Warsaw is that day's,
 * though UTC still calls it the evening before.
 */
final readonly class UserCalendar
{
    public function __construct(private string $timezone) {}

    public static function of(User $user): self
    {
        return new self($user->preferences->effectiveTimezone());
    }

    /** A moment as the user's clock showed it: their hour, their weekday. */
    public function moment(DateTimeInterface $moment): CarbonImmutable
    {
        return CarbonImmutable::instance($moment)->setTimezone($this->timezone);
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
     * The day it is for the user now, at its start: past midnight in Warsaw it
     * is already tomorrow there, though the server's clock still says today.
     */
    public function today(): CarbonImmutable
    {
        return CarbonImmutable::now($this->timezone)->startOfDay();
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

    private function onDay(string $day): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('!Y-m-d', $day, $this->timezone) ?: throw new InvalidArgumentException("Not a day: [{$day}].");
    }
}

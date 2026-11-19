<?php

namespace App\Users\Statistics;

/**
 * The days the statistics page reads, as calendar dates on the user's own
 * calendar (UserCalendar). `from` and `to` are both included; the whole
 * history has no bounds of its own until the history says where it starts.
 */
final readonly class StatisticsPeriod
{
    /** How an address names a period: a year (`2025`) or the whole history (`all`). A range of days travels as two days instead. */
    public const CODE_PATTERN = '^(\\d{4}|'.PeriodKind::All->value.')$';

    private function __construct(
        public PeriodKind $kind,
        /** Y-m-d, or null for the whole history. */
        public ?string $from,
        /** Y-m-d, or null for the whole history. */
        public ?string $to,
    ) {}

    public static function ofYear(int $year): self
    {
        return new self(PeriodKind::Year, sprintf('%04d-01-01', $year), sprintf('%04d-12-31', $year));
    }

    public static function whole(): self
    {
        return new self(PeriodKind::All, null, null);
    }

    /** The period an address names (see CODE_PATTERN); null for anything else. */
    public static function fromCode(string $code): ?self
    {
        return match (true) {
            $code === PeriodKind::All->value => self::whole(),
            preg_match('/'.self::CODE_PATTERN.'/', $code) === 1 => self::ofYear((int) $code),
            default => null,
        };
    }

    /** Two Y-m-d days in either order. */
    public static function between(string $from, string $to): self
    {
        return $from <= $to ? new self(PeriodKind::Range, $from, $to) : new self(PeriodKind::Range, $to, $from);
    }

    /** Whether a Y-m-d day of the user's lies inside. */
    public function contains(string $day): bool
    {
        return ($this->from === null || $day >= $this->from) && ($this->to === null || $day <= $this->to);
    }

    public function year(): ?int
    {
        return $this->kind === PeriodKind::Year && $this->from !== null ? (int) substr($this->from, 0, 4) : null;
    }
}

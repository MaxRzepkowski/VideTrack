<?php

namespace App\Catalog\Sets;

use App\Catalog\ChartOrder;

/**
 * Which chart a set copies: of which type, in which order, of which years,
 * and how many titles from the top. Travels as a set's `ref`.
 */
final readonly class ChartRef
{
    public function __construct(
        public string $type,
        public ChartOrder $order,
        public int $size,
        public ?int $fromYear = null,
        public ?int $toYear = null,
    ) {}

    public function key(string $provider): SetKey
    {
        $years = $this->fromYear === null && $this->toYear === null ? 'all' : "{$this->fromYear}-{$this->toYear}";

        return new SetKey(SetKind::Chart, $provider, "{$this->type}:{$this->order->value}:{$years}:{$this->size}");
    }

    /** The chart a set's ref names; null when it names none. */
    public static function parse(string $ref): ?self
    {
        if (preg_match('/^([a-z_]+):([a-z-]+):(all|(\d{4})-(\d{4})):(\d+)$/', $ref, $match) !== 1) {
            return null;
        }

        $order = ChartOrder::tryFrom($match[2]);

        return $order === null ? null : new self(
            $match[1],
            $order,
            (int) $match[6],
            $match[3] === 'all' ? null : (int) $match[4],
            $match[3] === 'all' ? null : (int) $match[5],
        );
    }
}

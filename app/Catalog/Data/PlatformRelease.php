<?php

namespace App\Catalog\Data;

use App\Catalog\DatePrecision;
use App\Catalog\ReleaseRegion;
use App\Support\Payload;
use JsonSerializable;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One dated release of a game on one platform: a port, an early access, a
 * next-gen patch. Unlike a film's ReleaseEntry it is keyed by platform and
 * release status rather than by country and type, and the place is a release
 * region (ReleaseRegion), not a country.
 */
#[TypeScript]
final readonly class PlatformRelease implements JsonSerializable
{
    public function __construct(
        public string $date,
        public ?string $platform = null,
        /** A release of another kind than a plain one (Early Access, a next-gen patch…), as the provider names it; null for a plain release. */
        public ?string $status = null,
        /** Null when the provider names none. */
        public ?ReleaseRegion $region = null,
        /** How much of `date` the provider knows; a stand-in day for "Q1 2028" is no premiere to land on. */
        public DatePrecision $precision = DatePrecision::Day,
    ) {}

    /**
     * A stored copy, or nothing when it no longer reads as one. One stored
     * without a precision reads as an exact day.
     *
     * @param  array<array-key, mixed>  $stored
     */
    public static function fromStored(array $stored): ?self
    {
        $date = Payload::string($stored, 'date');

        return $date === null ? null : new self(
            $date,
            Payload::string($stored, 'platform'),
            Payload::string($stored, 'status'),
            Payload::enum($stored, 'region', ReleaseRegion::class),
            Payload::enum($stored, 'precision', DatePrecision::class) ?? DatePrecision::Day,
        );
    }

    /**
     * @return array{
     *     date: string,
     *     platform: string|null,
     *     status: string|null,
     *     region: value-of<ReleaseRegion>|null,
     *     precision: value-of<DatePrecision>
     * }
     */
    public function jsonSerialize(): array
    {
        return [
            'date' => $this->date,
            'platform' => $this->platform,
            'status' => $this->status,
            'region' => $this->region?->value,
            'precision' => $this->precision->value,
        ];
    }
}

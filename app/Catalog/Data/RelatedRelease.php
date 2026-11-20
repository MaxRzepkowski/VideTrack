<?php

namespace App\Catalog\Data;

use App\Catalog\DatePrecision;
use App\Catalog\GameKind;
use App\Support\Payload;
use JsonSerializable;

/**
 * A release of its own that belongs to a game — its DLC, an expansion, a
 * remake, a remaster — as the game's provider lists it: enough to stand on
 * a calendar beside the game without being a title in the library.
 */
final readonly class RelatedRelease implements JsonSerializable
{
    /**
     * @param  list<PlatformRelease>  $platformReleases
     */
    public function __construct(
        /** The release's own ref at the game's provider. */
        public string $ref,
        public string $title,
        public GameKind $kind,
        public ?string $posterPath,
        /** Its first date anywhere. */
        public string $date,
        public DatePrecision $precision = DatePrecision::Day,
        /** Every regional date per platform, as a game's own are kept. */
        public array $platformReleases = [],
    ) {}

    /**
     * A stored copy, or nothing when it no longer reads as one.
     *
     * @param  array<array-key, mixed>  $stored
     */
    public static function fromStored(array $stored): ?self
    {
        $ref = Payload::string($stored, 'ref');
        $title = Payload::string($stored, 'title');
        $kind = Payload::enum($stored, 'kind', GameKind::class);
        $date = Payload::string($stored, 'date');

        if ($ref === null || $title === null || $kind === null || $date === null) {
            return null;
        }

        return new self(
            $ref,
            $title,
            $kind,
            Payload::string($stored, 'posterPath'),
            $date,
            Payload::enum($stored, 'precision', DatePrecision::class) ?? DatePrecision::Day,
            Payload::listOf($stored['platformReleases'] ?? null, PlatformRelease::fromStored(...)),
        );
    }

    /** The same, less every platform's date still ahead of `$day` (Y-m-d). */
    public function releasedBy(string $day): self
    {
        return new self(
            $this->ref,
            $this->title,
            $this->kind,
            $this->posterPath,
            $this->date,
            $this->precision,
            array_values(array_filter($this->platformReleases, fn (PlatformRelease $release): bool => $release->date <= $day)),
        );
    }

    /**
     * @return array{
     *     ref: string,
     *     title: string,
     *     kind: value-of<GameKind>,
     *     posterPath: string|null,
     *     date: string,
     *     precision: value-of<DatePrecision>,
     *     platformReleases: list<array<string, mixed>>
     * }
     */
    public function jsonSerialize(): array
    {
        return [
            'ref' => $this->ref,
            'title' => $this->title,
            'kind' => $this->kind->value,
            'posterPath' => $this->posterPath,
            'date' => $this->date,
            'precision' => $this->precision->value,
            'platformReleases' => array_map(fn (PlatformRelease $release): array => $release->jsonSerialize(), $this->platformReleases),
        ];
    }
}

<?php

namespace App\Catalog\Data;

use App\Catalog\BookKind;
use App\Catalog\Creators\CreatorKind;
use App\Catalog\DatePrecision;
use App\Catalog\EpisodeBadge;
use App\Catalog\EpisodeType;
use App\Catalog\GameKind;
use App\Enums\ReleaseKind;
use BackedEnum;
use JsonSerializable;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Provider-agnostic summary of a media position — enough to render a media
 * card. `ref` is an opaque provider reference (the provider knows how to
 * parse it back).
 *
 * `extra` on the wire: the typed fields below (`seasonNumber` … `authors`),
 * the context a surface adds (`showTitle` … `itemCount`), and whatever else
 * the provider keeps for its type's page.
 *
 * @phpstan-type CardExtra array{
 *     seasonNumber?: int,
 *     runtime?: int,
 *     overview?: string,
 *     releaseKind?: value-of<ReleaseKind>,
 *     platform?: string,
 *     releaseStatus?: string,
 *     releasePrecision?: value-of<DatePrecision>,
 *     episodeType?: value-of<EpisodeType>,
 *     episodeBadge?: value-of<EpisodeBadge>,
 *     kind?: value-of<CreatorKind>,
 *     makes?: string,
 *     authors?: list<string>,
 *     showTitle?: string,
 *     showRef?: string,
 *     showPoster?: string|null,
 *     showBackdrop?: string|null,
 *     coverColor?: string|null,
 *     followedVia?: string,
 *     releaseNote?: string,
 *     episodeCount?: int|null,
 *     gameType?: value-of<GameKind>,
 *     bookKind?: value-of<BookKind>,
 *     awaitedBy?: int,
 *     knownForDepartment?: string|null,
 *     knownFor?: list<string>,
 *     itemCount?: int,
 *     ...<string, mixed>
 * }
 */
#[TypeScript]
final readonly class MediaSummary implements JsonSerializable
{
    /** What each key of `extra` the card names holds (`CardExtra` above), to hold a stored bag to it. */
    private const EXTRA_KEYS = [
        'seasonNumber' => 'int',
        'runtime' => 'int',
        'overview' => 'string',
        'releaseKind' => ReleaseKind::class,
        'platform' => 'string',
        'releaseStatus' => 'string',
        'releasePrecision' => DatePrecision::class,
        'episodeType' => EpisodeType::class,
        'episodeBadge' => EpisodeBadge::class,
        'kind' => CreatorKind::class,
        'makes' => 'string',
        'authors' => 'strings',
        'showTitle' => 'string',
        'showRef' => 'string',
        'showPoster' => '?string',
        'showBackdrop' => '?string',
        'coverColor' => '?string',
        'followedVia' => 'string',
        'releaseNote' => 'string',
        'episodeCount' => '?int',
        'gameType' => GameKind::class,
        'bookKind' => BookKind::class,
        'awaitedBy' => 'int',
        'knownForDepartment' => '?string',
        'knownFor' => 'strings',
        'itemCount' => 'int',
    ];

    public function __construct(
        public string $type,
        public string $ref,
        /**
         * Where the item comes from. For a catalogue entry `ref` is valid
         * there, so the pair links the summary to its page; a list card
         * (ref = our list id) keeps its source ref in `extra`.
         */
        public string $provider,
        public string $title,
        public ?int $year = null,
        public ?string $posterPath = null,
        public ?string $backdropPath = null,
        public ?float $communityRating = null,
        public ?string $releaseDate = null,
        /** Full ISO-8601 broadcast instant (TVmaze airstamp) when the show has a real airing slot; NULL otherwise. */
        public ?string $releaseTime = null,
        /** Season or episode number within the parent. */
        public ?int $number = null,
        /**
         * Minutes to get through the whole thing (a show: every episode of
         * every season). Only locally tracked items carry it — provider
         * results have no stored tree to sum, so theirs stays NULL.
         */
        public ?int $totalRuntime = null,
        /** The season an episode belongs to. */
        public ?int $seasonNumber = null,
        /** Minutes of this one item, where the provider lists it per child (episodes). */
        public ?int $runtime = null,
        /** A child's synopsis; top-level titles carry theirs in MediaDetails. */
        public ?string $overview = null,
        /**
         * Which release `releaseDate` stands for, where a title has several
         * and a calendar lists them apart: a film's premiere or home release,
         * a game's platforms and its release status (Early Access…).
         */
        public ?ReleaseKind $releaseKind = null,
        public ?string $platform = null,
        public ?string $releaseStatus = null,
        /** @var CardExtra Display-only fields the core never reads (show title, a game's type, …). */
        public array $extra = [],
        /**
         * How far the progress counter runs, in the type's unit: a book's
         * pages, a manga's chapters, a comic volume's issues. Null where the
         * type counts time, or nothing, or the provider never said.
         */
        public ?int $progressTotal = null,
        /** An episode's place in its season, as the provider marks it (finale, mid-season finale). */
        public ?EpisodeType $episodeType = null,
        /** @var list<string> who wrote a book or a manga, as the provider names them */
        public array $authors = [],
        /** What the episode opens or closes in its show (a premiere, a finale): the chip its card carries. */
        public ?EpisodeBadge $episodeBadge = null,
        /** Which page a company card opens: a studio's or a network's. */
        public ?CreatorKind $creatorKind = null,
        /** The media type a company card's company makes, for its badge. */
        public ?string $makes = null,
        /** How many people rated it at the provider: how widely a title is known, where a listing says. */
        public ?int $votes = null,
        /** How much of `releaseDate` the provider knows: a game "in 2028" has a stand-in day, not a premiere. */
        public DatePrecision $releasePrecision = DatePrecision::Day,
        /**
         * Other names the provider knows the title by (an acronym, another
         * spelling, a title in another language), for placing a title by name.
         * A card does not show them, so they stay off the wire.
         *
         * @var list<string>
         */
        public array $alternativeTitles = [],
    ) {}

    /** How the user-state map addresses this position: "type:ref". */
    public function key(): string
    {
        return self::keyOf($this->type, $this->ref);
    }

    /** The same address for a position known only by its type and ref. */
    public static function keyOf(string $type, string $ref): string
    {
        return "{$type}:{$ref}";
    }

    /**
     * @return array{
     *     type: string,
     *     ref: string,
     *     provider: string,
     *     title: string,
     *     year: int|null,
     *     posterPath: string|null,
     *     backdropPath: string|null,
     *     communityRating: float|null,
     *     releaseDate: string|null,
     *     releaseTime: string|null,
     *     number: int|null,
     *     totalRuntime: int|null,
     *     progressTotal: int|null,
     *     extra: CardExtra
     * }
     */
    public function jsonSerialize(): array
    {
        return [
            'type' => $this->type,
            'ref' => $this->ref,
            'provider' => $this->provider,
            'title' => $this->title,
            'year' => $this->year,
            'posterPath' => $this->posterPath,
            'backdropPath' => $this->backdropPath,
            'communityRating' => $this->communityRating,
            'releaseDate' => $this->releaseDate,
            'releaseTime' => $this->releaseTime,
            'number' => $this->number,
            'totalRuntime' => $this->totalRuntime,
            'progressTotal' => $this->progressTotal,
            'extra' => $this->wireExtra(),
        ];
    }

    /**
     * `extra` as the pages receive it: the typed fields ride in
     * it, so a card reads `extra.seasonNumber` whichever side built it.
     *
     * @return CardExtra
     */
    public function wireExtra(): array
    {
        return [
            ...$this->extra,
            ...array_filter([
                'seasonNumber' => $this->seasonNumber,
                'runtime' => $this->runtime,
                'overview' => $this->overview,
                'releaseKind' => $this->releaseKind?->value,
                'platform' => $this->platform,
                'releaseStatus' => $this->releaseStatus,
                // Only a date short of a day says so; every other card carries null.
                'releasePrecision' => $this->releasePrecision === DatePrecision::Day ? null : $this->releasePrecision->value,
                'episodeType' => $this->episodeType?->value,
                'episodeBadge' => $this->episodeBadge?->value,
                'kind' => $this->creatorKind?->value,
                'makes' => $this->makes,
            ], fn (mixed $value): bool => $value !== null),
            ...($this->authors === [] ? [] : ['authors' => $this->authors]),
        ];
    }

    /**
     * A card's `extra` read back from storage (a title's metadata column): a
     * key the card names but holding another kind of value, written by an
     * older shape of it, is dropped; the rest of the bag rides as stored.
     *
     * @param  array<array-key, mixed>  $stored
     * @return CardExtra
     */
    public static function extraFromStored(array $stored): array
    {
        $extra = [];

        foreach ($stored as $key => $value) {
            if (is_string($key) && (! isset(self::EXTRA_KEYS[$key]) || self::fits($value, self::EXTRA_KEYS[$key]))) {
                $extra[$key] = $value;
            }
        }

        // PHPStan cannot follow a checked open bag; every named key was checked above.
        /** @var CardExtra $extra */
        return $extra;
    }

    private static function fits(mixed $value, string $kind): bool
    {
        return match ($kind) {
            'int' => is_int($value),
            '?int' => $value === null || is_int($value),
            'string' => is_string($value),
            '?string' => $value === null || is_string($value),
            'strings' => is_array($value) && array_is_list($value) && $value === array_filter($value, is_string(...)),
            default => is_string($value) && is_a($kind, BackedEnum::class, true) && $kind::tryFrom($value) !== null,
        };
    }
}

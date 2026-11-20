<?php

namespace App\Catalog;

use App\Catalog\Creators\CreatorKind;
use App\Catalog\Data\CertificationData;
use App\Catalog\Data\CollectionLink;
use App\Catalog\Data\CreatorLink;
use App\Catalog\Data\KeywordData;
use App\Catalog\Data\MediaDetails;
use App\Catalog\Data\MediaSummary;
use App\Catalog\Data\PlatformRelease;
use App\Catalog\Data\RelatedRelease;
use App\Catalog\Data\ReleaseEntry;
use App\Models\MediaItem;
use App\Support\Payload;
use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;

/**
 * The `metadata` column of a library row: what a provider said about an item
 * that no column holds. It is written when a title is persisted and read back
 * when the provider is down (DetailsFromLibrary), so both directions live here
 * and a renamed key cannot break one side alone.
 *
 * The typed fields are the ones the core interprets. Everything else a
 * provider sent (budget, network, badges…) rides along in `extra` as it came.
 * What a module learns about a row on its own (TVmaze's id for a show) lives
 * in `modules`: the provider never sends it, so a refresh must not drop it.
 */
final readonly class ItemMetadata
{
    /** Keys the typed fields are stored under; the rest of the column is `extra`. */
    private const TYPED_KEYS = [
        'tagline', 'originalLanguage', 'certification', 'genres',
        'certifications', 'releases', 'platformReleases', 'relatedReleases', 'keywords', 'externalLinks', 'modules', 'episodeType', 'episodeBadge', 'authors', 'wikidataId', 'collection',
    ];

    public function __construct(
        public ?string $tagline = null,
        public ?string $originalLanguage = null,
        /** The chip's age certification, in the region it was persisted for. */
        public ?string $certification = null,
        /** @var list<string> */
        public array $genres = [],
        /** @var list<CertificationData> */
        public array $certifications = [],
        /** @var list<ReleaseEntry> */
        public array $releases = [],
        /** @var list<PlatformRelease> */
        public array $platformReleases = [],
        /** @var list<KeywordData> */
        public array $keywords = [],
        /** @var array<string, string> label => url */
        public array $externalLinks = [],
        /** @var array<string, mixed> */
        public array $extra = [],
        /** @var array<string, mixed> key => what a module remembered */
        public array $modules = [],
        public ?EpisodeType $episodeType = null,
        public ?EpisodeBadge $episodeBadge = null,
        /** @var list<string> */
        public array $authors = [],
        public ?string $wikidataId = null,
        public ?CollectionLink $collection = null,
        /** @var list<RelatedRelease>|null a game's dated DLC, remakes and remasters; null until its provider lists them, so a first listing announces none */
        public ?array $relatedReleases = null,
    ) {}

    public static function fromDetails(MediaDetails $details): self
    {
        return new self(
            tagline: $details->tagline,
            originalLanguage: $details->originalLanguage,
            certification: $details->certification,
            genres: $details->genres,
            certifications: $details->certifications,
            releases: $details->releases,
            platformReleases: $details->platformReleases,
            keywords: $details->keywords,
            externalLinks: $details->externalLinks,
            extra: [...$details->summary->extra, ...$details->extra],
            episodeType: $details->summary->episodeType,
            episodeBadge: $details->summary->episodeBadge,
            authors: $details->summary->authors,
            wikidataId: $details->wikidataId,
            collection: $details->collection,
            relatedReleases: $details->relatedReleases,
        );
    }

    /** A child row (a season, an episode) keeps what its card carries beyond the columns. */
    public static function fromSummary(MediaSummary $summary): self
    {
        return new self(extra: $summary->extra, episodeType: $summary->episodeType, episodeBadge: $summary->episodeBadge, authors: $summary->authors);
    }

    /**
     * A row whose field is missing or of another shape reads back as far as
     * it still makes sense; an entry that does not is skipped
     * rather than failing the page that only wanted the rest.
     *
     * @param  array<array-key, mixed>|null  $stored
     */
    public static function fromStored(?array $stored): self
    {
        $stored ??= [];

        return new self(
            tagline: Payload::string($stored, 'tagline'),
            originalLanguage: Payload::string($stored, 'originalLanguage'),
            certification: Payload::string($stored, 'certification'),
            genres: Payload::strings($stored['genres'] ?? null),
            certifications: Payload::listOf($stored['certifications'] ?? null, CertificationData::fromStored(...)),
            releases: Payload::listOf($stored['releases'] ?? null, ReleaseEntry::fromStored(...)),
            platformReleases: Payload::listOf($stored['platformReleases'] ?? null, PlatformRelease::fromStored(...)),
            keywords: Payload::listOf($stored['keywords'] ?? null, KeywordData::fromStored(...)),
            externalLinks: Payload::stringMap($stored['externalLinks'] ?? null),
            extra: Payload::named(array_diff_key($stored, array_flip(self::TYPED_KEYS))),
            modules: Payload::named($stored['modules'] ?? null),
            episodeType: Payload::enum($stored, 'episodeType', EpisodeType::class),
            episodeBadge: Payload::enum($stored, 'episodeBadge', EpisodeBadge::class),
            authors: Payload::strings($stored['authors'] ?? null),
            wikidataId: Payload::string($stored, 'wikidataId'),
            collection: is_array($stored['collection'] ?? null) ? CollectionLink::fromStored($stored['collection']) : null,
            relatedReleases: is_array($stored['relatedReleases'] ?? null) ? Payload::listOf($stored['relatedReleases'], RelatedRelease::fromStored(...)) : null,
        );
    }

    /**
     * Narrows a query on `media_items` to the rows that carry one of the
     * typed fields: the one place a query names a stored key.
     *
     * @param  Builder<MediaItem>  $query
     * @return Builder<MediaItem>
     */
    public static function having(Builder $query, string $field): Builder
    {
        return $query->whereNotNull(self::column($field));
    }

    /**
     * The same, narrowed to the rows that do not carry it.
     *
     * @param  Builder<MediaItem>  $query
     * @return Builder<MediaItem>
     */
    public static function lacking(Builder $query, string $field): Builder
    {
        return $query->whereNull(self::column($field));
    }

    /** Where a typed field is stored, for a query. */
    private static function column(string $field): string
    {
        return in_array($field, self::TYPED_KEYS, true) ? "media_items.metadata->{$field}" : throw new InvalidArgumentException("Item metadata has no typed field [{$field}].");
    }

    /** What a module remembered under its key; null when it never did. */
    public function module(string $key): mixed
    {
        return $this->modules[$key] ?? null;
    }

    public function withModule(string $key, mixed $value): self
    {
        return $this->with(modules: [...$this->modules, $key => $value]);
    }

    /** A fresh read from the provider, keeping what modules remembered on the row it replaces. */
    public function keepingModulesOf(self $stored): self
    {
        return $this->with(modules: [...$stored->modules, ...$this->modules]);
    }

    /**
     * The column value. An empty field writes no key, so nothing stores a key
     * just to hold a null, and the order is fixed: an unchanged title persists
     * byte-identical and its row is not rewritten.
     *
     * @return array<string, mixed>|null
     */
    public function toStored(): ?array
    {
        $stored = $this->extra;

        if ($this->genres !== []) {
            $stored['genres'] = $this->genres;
        }

        $typed = array_filter([
            'tagline' => $this->tagline,
            'originalLanguage' => $this->originalLanguage,
            'certification' => $this->certification,
            'keywords' => array_map(fn (KeywordData $keyword): array => $keyword->jsonSerialize(), $this->keywords),
            'certifications' => array_map(fn (CertificationData $certification): array => $certification->jsonSerialize(), $this->certifications),
            'releases' => array_map(fn (ReleaseEntry $release): array => $release->jsonSerialize(), $this->releases),
            'platformReleases' => array_map(fn (PlatformRelease $release): array => $release->jsonSerialize(), $this->platformReleases),
            'externalLinks' => $this->externalLinks,
            'modules' => $this->modules,
            'episodeType' => $this->episodeType?->value,
            'episodeBadge' => $this->episodeBadge?->value,
            'authors' => $this->authors,
            'wikidataId' => $this->wikidataId,
            'collection' => $this->collection?->jsonSerialize(),
        ], fn (mixed $value): bool => $value !== null && $value !== '' && $value !== []);

        foreach ($typed as $key => $value) {
            $stored[$key] = $value;
        }

        // Kept also when empty: a provider that listed none said so.
        if ($this->relatedReleases !== null) {
            $stored['relatedReleases'] = array_map(fn (RelatedRelease $release): array => $release->jsonSerialize(), $this->relatedReleases);
        }

        return $stored === [] ? null : $stored;
    }

    /** The total a reading counter runs to (a book's pages, a manga's chapters); null when the provider never said. */
    public function progressTotal(ProgressUnit $unit): ?int
    {
        return Payload::positiveInt($this->extra, $unit->value);
    }

    /**
     * The day a film stops being cinema-only: its earliest digital or disc
     * release anywhere. It may still lie ahead — that is the useful case: it
     * answers "when can I finally watch this at all?". Null when it has none.
     */
    public function homeReleaseDate(): ?string
    {
        $dates = [];

        foreach ($this->releases as $release) {
            if (in_array($release->kind, [FilmReleaseType::Digital, FilmReleaseType::Disc], true)) {
                $dates[] = $release->date;
            }
        }

        return $dates === [] ? null : min($dates);
    }

    /** A film's free-text note on its home release ("Netflix"); null when the provider gave none. */
    public function homeReleaseNote(): ?string
    {
        return Payload::string($this->extra, 'homeReleaseNote');
    }

    /** A cover's dominant colour (books), which tints a card that has no backdrop. */
    public function coverColor(): ?string
    {
        return Payload::string($this->extra, 'coverColor');
    }

    /** The title's IMDb id (`tt…`), which the rating services outside VideTrack key films by. */
    public function imdbId(): ?string
    {
        return Payload::string($this->extra, 'imdbId');
    }

    /** A book's ISBN-13, of the edition its provider shows; the book services outside VideTrack place a book by it. */
    public function isbn(): ?string
    {
        return Payload::string($this->extra, 'isbn');
    }

    /** A book's Goodreads id, which a Goodreads library file keys books by. */
    public function goodreadsId(): ?string
    {
        return Payload::string($this->extra, 'goodreadsId');
    }

    /** The title in its own language, kept when the stored one is a translation's (a book's local edition). */
    public function originalTitle(): ?string
    {
        return Payload::string($this->extra, 'originalTitle');
    }

    /** The show's TheTVDB id, which the air-time services key series by. */
    public function tvdbId(): ?int
    {
        return Payload::positiveInt($this->extra, 'tvdbId');
    }

    /** A film's budget in US dollars; null when the provider never said. */
    public function budget(): ?int
    {
        return Payload::positiveInt($this->extra, 'budget');
    }

    /** A film's box-office revenue in US dollars; null when the provider never said. */
    public function revenue(): ?int
    {
        return Payload::positiveInt($this->extra, 'revenue');
    }

    /** @return list<CreatorLink> the production companies, each with its page where the provider has one */
    public function studios(): array
    {
        return $this->creatorLinks('studios', CreatorKind::Company);
    }

    /** @return list<CreatorLink> the networks and services a show ran on, each with its page where the provider has one */
    public function networks(): array
    {
        return $this->creatorLinks('networks', CreatorKind::Network);
    }

    /** @return list<string> the countries that produced the title, as ISO 3166-1 codes */
    public function productionCountries(): array
    {
        return Payload::strings($this->extra['productionCountries'] ?? null);
    }

    /**
     * Where the title comes from, as ISO 3166-1 codes: a show's countries of
     * origin, otherwise the countries that produced it (films carry only those).
     *
     * @return list<string>
     */
    public function originCountries(): array
    {
        $origin = Payload::strings($this->extra['originCountry'] ?? null);

        return $origin !== [] ? $origin : $this->productionCountries();
    }

    /** The same, less every release row still ahead of `$day` (Y-m-d). */
    public function releasedBy(string $day): self
    {
        return $this->with(
            releases: array_values(array_filter($this->releases, fn (ReleaseEntry $release): bool => $release->date <= $day)),
            platformReleases: array_values(array_filter($this->platformReleases, fn (PlatformRelease $release): bool => $release->date <= $day)),
            relatedReleases: $this->relatedReleases === null ? null : array_values(array_map(
                fn (RelatedRelease $release): RelatedRelease => $release->releasedBy($day),
                array_filter($this->relatedReleases, fn (RelatedRelease $release): bool => $release->date <= $day),
            )),
        );
    }

    /**
     * A copy with the given fields replaced, field by field: an unpacked
     * array of the properties loses their types and breaks silently once a
     * property is not a constructor parameter.
     *
     * @param  list<ReleaseEntry>|null  $releases
     * @param  list<PlatformRelease>|null  $platformReleases
     * @param  array<string, mixed>|null  $modules
     * @param  list<RelatedRelease>|null  $relatedReleases
     */
    private function with(?array $releases = null, ?array $platformReleases = null, ?array $modules = null, ?array $relatedReleases = null): self
    {
        return new self(
            tagline: $this->tagline,
            originalLanguage: $this->originalLanguage,
            certification: $this->certification,
            genres: $this->genres,
            certifications: $this->certifications,
            releases: $releases ?? $this->releases,
            platformReleases: $platformReleases ?? $this->platformReleases,
            keywords: $this->keywords,
            externalLinks: $this->externalLinks,
            extra: $this->extra,
            modules: $modules ?? $this->modules,
            episodeType: $this->episodeType,
            episodeBadge: $this->episodeBadge,
            authors: $this->authors,
            wikidataId: $this->wikidataId,
            collection: $this->collection,
            relatedReleases: $relatedReleases ?? $this->relatedReleases,
        );
    }

    /** @return list<CreatorLink> the links stored under `$key`, of `$kind` where a row does not say */
    private function creatorLinks(string $key, CreatorKind $kind): array
    {
        $links = [];

        foreach (Payload::list($this->extra[$key] ?? null) as $row) {
            $name = Payload::string($row, 'name');

            if ($name !== null) {
                $links[] = new CreatorLink(
                    name: $name,
                    kind: Payload::enum($row, 'kind', CreatorKind::class) ?? $kind,
                    ref: Payload::string($row, 'ref'),
                    provider: Payload::string($row, 'provider'),
                    logoPath: Payload::string($row, 'logoPath'),
                );
            }
        }

        return $links;
    }
}

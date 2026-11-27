<?php

namespace App\Catalog\Providers\Hardcover;

use App\Catalog\BookRole;
use App\Catalog\Creators\CreatorKind;
use App\Catalog\Data\CreatorLink;
use App\Catalog\Data\MediaSummary;
use App\Catalog\Department;
use App\Support\Payload;
use Carbon\CarbonImmutable;
use LogicException;

/**
 * Reading Hardcover's two answer shapes, a Hasura books row and a Typesense
 * search document, into the shared DTOs; shared by the provider's parts.
 */
final readonly class HardcoverPayload
{
    /** Hasura selection shared by every books listing (popular, upcoming, trending, an author's shelf). */
    public const SUMMARY_FIELDS = 'id title release_date release_year rating cached_image cached_contributors book_category_id';

    /** Hardcover's platform id for Goodreads in `book_mappings`. */
    public const GOODREADS_PLATFORM = 1;

    /** The word Hardcover credits a book's own writer by; a contribution without one is the author's too. */
    private const AUTHOR_ROLE = 'Author';

    /** Hardcover's words for the parts it credits beside the author. */
    private const ROLES = [
        'Translator' => BookRole::Translator,
        'Illustrator' => BookRole::Illustrator,
        'Narrator' => BookRole::Narrator,
        'Cover Artist' => BookRole::CoverArtist,
        'Editor' => BookRole::Editor,
        'Foreword' => BookRole::Foreword,
        'Afterword' => BookRole::Afterword,
        'Adaptation' => BookRole::Adaptation,
        'Original Author' => BookRole::OriginalAuthor,
    ];

    public function code(): string
    {
        return HardcoverProvider::CODE;
    }

    /** The part Hardcover's word names; null for one it has no case for. */
    public function bookRole(string $word): ?BookRole
    {
        return self::ROLES[$word] ?? null;
    }

    /** The department a part beside the author's belongs to; a word with no case is crew. */
    public function department(string $word): Department
    {
        return $this->bookRole($word)?->department() ?? Department::Crew;
    }

    /**
     * Summaries out of a Typesense search response (`search.results.hits`),
     * every one of them: a search is the user naming a title and must stay
     * complete — plenty of Polish editions have no cover in Hardcover. A genre
     * shelf, curated, drops the coverless ones itself.
     *
     * @param  array<array-key, mixed>  $data
     * @return list<MediaSummary>
     */
    public function documentSummaries(array $data): array
    {
        $summaries = [];

        foreach ((array) data_get($data, 'search.results.hits', []) as $hit) {
            $document = is_array($hit) ? ($hit['document'] ?? null) : null;

            if (! is_array($document)) {
                continue;
            }

            $summaries[] = $this->summary($document);
        }

        return $summaries;
    }

    /**
     * Summaries out of a Hasura books listing (`books`), covers required.
     *
     * @param  array<array-key, mixed>  $data
     * @return list<MediaSummary>
     */
    public function bookSummaries(array $data): array
    {
        $summaries = [];

        foreach ((array) ($data['books'] ?? []) as $book) {
            if (is_array($book) && $this->coverUrl($book) !== null) {
                $summaries[] = $this->summary($book);
            }
        }

        return array_slice($summaries, 0, 20);
    }

    /**
     * One summary out of either shape: a Hasura books row (cached_image,
     * cached_contributors, release_date) or a search document (image,
     * author_names, release_year).
     *
     * @param  array<array-key, mixed>  $data
     */
    public function summary(array $data): MediaSummary
    {
        return new MediaSummary(
            type: 'book',
            ref: (string) (Payload::positiveInt($data, 'id') ?? 0),
            provider: $this->code(),
            title: Payload::string($data, 'title') ?? '',
            year: $this->year($data),
            posterPath: $this->coverUrl($data),
            communityRating: $this->rating($data),
            releaseDate: $this->releaseDay($data)?->toDateString(),
            // Asked for by the book's own page, not by listings.
            votes: Payload::positiveInt($data, 'ratings_count'),
            progressTotal: Payload::positiveInt($data, 'pages'),
            authors: $this->authors($data),
            extra: array_filter(['bookKind' => HardcoverBookKind::of($data)?->value]),
        );
    }

    /**
     * The year a book or an edition came out: Hardcover's own year, else the
     * one of its date.
     *
     * @param  array<array-key, mixed>  $data
     */
    public function year(array $data): ?int
    {
        return Payload::positiveInt($data, 'release_year') ?? $this->releaseDay($data)?->year;
    }

    /**
     * Hardcover's release date as a calendar day. Ancient works come dated
     * before the common era ("0701-01-01 BC"), which no card can show as a
     * day or a year: no date at all.
     *
     * @param  array<array-key, mixed>  $data
     */
    private function releaseDay(array $data): ?CarbonImmutable
    {
        return Payload::calendarDay($data['release_date'] ?? null);
    }

    /**
     * Author display names from whichever field the payload carries.
     *
     * @param  array<array-key, mixed>  $data
     * @return list<string>
     */
    private function authors(array $data): array
    {
        // Search documents: flat author_names.
        $names = array_values(array_filter(
            (array) ($data['author_names'] ?? []),
            fn ($name): bool => is_string($name) && $name !== '',
        ));

        if ($names !== []) {
            return $names;
        }

        // Books rows: the people credited as the book's authors, not its translator or illustrator.
        foreach ($this->contributors($data) as $contributor) {
            if ($contributor['role'] === null && ! in_array($contributor['name'], $names, true)) {
                $names[] = $contributor['name'];
            }
        }

        return $names;
    }

    /**
     * Book ids from a list Hardcover keeps (`[312460, "2459845"]`), in order.
     *
     * @return list<int>
     */
    public function bookIds(mixed $value): array
    {
        return array_values(array_map(intval(...), array_filter(Payload::idList($value), ctype_digit(...))));
    }

    /**
     * Everyone a books row credits (`cached_contributors`), in its order, with
     * their part: null for an author, else Hardcover's word ("Translator",
     * "Illustrator").
     *
     * @param  array<array-key, mixed>  $data
     * @return list<array{name: string, id: string|null, role: string|null}>
     */
    public function contributors(array $data): array
    {
        $contributors = [];

        foreach (Payload::list($data['cached_contributors'] ?? null) as $contributor) {
            $author = Payload::map($contributor['author'] ?? null);
            $name = Payload::string($author, 'name');

            if ($name === null) {
                continue;
            }

            $role = Payload::string($contributor, 'contribution');

            $contributors[] = [
                'name' => $name,
                'id' => Payload::id($author['id'] ?? null),
                'role' => $role === self::AUTHOR_ROLE ? null : $role,
            ];
        }

        return $contributors;
    }

    /**
     * Authors as linkable creators — the details block that reaches the author
     * page. Only the Hasura books row carries author ids (`cached_contributors`);
     * search documents (flat `author_names`) have none, so this returns the
     * link-bearing shape only where an id exists.
     *
     * @param  array<array-key, mixed>  $data
     * @return list<CreatorLink>
     */
    public function authorLinks(array $data): array
    {
        $links = [];
        $seen = [];

        foreach ($this->contributors($data) as $contributor) {
            if ($contributor['role'] !== null || isset($seen[$contributor['name']])) {
                continue;
            }

            $seen[$contributor['name']] = true;

            $links[] = new CreatorLink(
                name: $contributor['name'],
                kind: CreatorKind::Person,
                ref: $contributor['id'],
                provider: $this->code(),
            );
        }

        return $links;
    }

    /**
     * The series Hardcover features on a book and the book's place in it
     * (fractional for a companion: #2.5), what the page's chip and its link
     * to the series both read.
     *
     * @param  array<array-key, mixed>  $data
     * @return array{id: string|null, name: string, position: float|null}|null
     */
    public function featuredSeries(array $data): ?array
    {
        $featured = Payload::map($data['cached_featured_series'] ?? null);
        $series = Payload::map($featured['series'] ?? null);
        $name = Payload::string($series, 'name');

        return $name === null
            ? null
            : ['id' => Payload::id($series['id'] ?? null), 'name' => $name, 'position' => Payload::float($featured, 'position')];
    }

    /**
     * Featured series membership, e.g. "Middle Earth #1".
     *
     * @param  array<array-key, mixed>  $data
     * @return array{name: string, position: float|null}|null
     */
    public function series(array $data): ?array
    {
        $featured = $this->featuredSeries($data);

        return $featured === null ? null : ['name' => $featured['name'], 'position' => $featured['position']];
    }

    /**
     * Tag names grouped by Hardcover category (Genre / Mood / Content
     * Warning / Tag), most-tagged first — the order cached_tags already has.
     *
     * @param  array<array-key, mixed>  $data
     * @return array<string, list<string>>
     */
    public function tagNames(array $data): array
    {
        $grouped = [];

        foreach ((array) ($data['cached_tags'] ?? []) as $category => $tags) {
            foreach ((array) $tags as $tag) {
                $name = is_array($tag) ? Payload::string($tag, 'tag') : null;

                if ($name !== null) {
                    $grouped[(string) $category][] = $name;
                }
            }
        }

        return $grouped;
    }

    /**
     * Cover URL out of either image shape (`cached_image` on books rows,
     * `image` on search documents).
     *
     * @param  array<array-key, mixed>  $data
     */
    public function coverUrl(array $data): ?string
    {
        $url = data_get($data, 'cached_image.url') ?? data_get($data, 'image.url');

        return is_string($url) && str_starts_with($url, 'http') ? $url : null;
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    public function coverColor(array $data): ?string
    {
        $color = data_get($data, 'cached_image.color');

        return is_string($color) && preg_match('/^#[0-9a-fA-F]{6}$/', $color) === 1 ? $color : null;
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    private function rating(array $data): ?float
    {
        // Hardcover ratings are 0–5; the app uses the 0–10 scale everywhere.
        $rating = is_numeric($data['rating'] ?? null) ? (float) $data['rating'] : 0.0;

        return $rating > 0 ? round($rating * 2, 1) : null;
    }

    public function ttl(string $kind): int
    {
        return config()->integer("tracker.hardcover.cache.{$kind}");
    }

    public function assertBook(string $type): void
    {
        if ($type !== 'book') {
            throw new LogicException("Hardcover does not serve discover listings for type [{$type}].");
        }
    }
}

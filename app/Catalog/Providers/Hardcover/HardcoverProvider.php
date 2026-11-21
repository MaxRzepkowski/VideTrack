<?php

namespace App\Catalog\Providers\Hardcover;

use App\Catalog\Contracts\MetadataProvider;
use App\Catalog\Contracts\SupportsCollections;
use App\Catalog\Contracts\SupportsDiscovery;
use App\Catalog\Contracts\SupportsExternalIdLookup;
use App\Catalog\Contracts\SupportsIsbnLookup;
use App\Catalog\Contracts\SupportsPersonPage;
use App\Catalog\Contracts\SupportsSimilarTitles;
use App\Catalog\Contracts\SupportsTrending;
use App\Catalog\Data\CollectionDetails;
use App\Catalog\Data\GenreOption;
use App\Catalog\Data\MediaDetails;
use App\Catalog\Data\MediaSummary;
use App\Catalog\Data\Paginated;
use App\Catalog\Data\PersonDetails;
use App\Catalog\ExternalIdSource;
use App\Catalog\MatureContent;
use App\Support\Payload;

/**
 * Hardcover implementation of the metadata contracts — the `book` module.
 * Books are flat (no hierarchy); refs are Hardcover
 * numeric book ids. Hardcover describes a book in English; only a book's
 * page reads the locale, for the title and cover of the reader's edition.
 * Cover URLs are absolute assets-CDN URLs (`mediaImage` passes `http…`
 * through).
 *
 * The face the registry knows: search, similar books, discovery and the
 * Goodreads id lookup live here, a book's page in HardcoverBookPage, an
 * author's page in HardcoverAuthors, a series in HardcoverSeries, the reading
 * of both answer shapes in HardcoverPayload.
 */
class HardcoverProvider implements MetadataProvider, SupportsCollections, SupportsDiscovery, SupportsExternalIdLookup, SupportsIsbnLookup, SupportsPersonPage, SupportsSimilarTitles, SupportsTrending
{
    /** Pages of one genre offered: more than anyone browses. */
    private const MAX_GENRE_PAGES = 100;

    /** Books on one page of a genre listing, and in one answer to a search. */
    private const PAGE_SIZE = 20;

    /** Trending books shown, asked with room for the ones that have no cover. */
    private const TRENDING_SHOWN = 20;

    private const TRENDING_ASKED = 30;

    /**
     * Similar books asked for, the most similar first: more than the page's
     * shelf shows, with room for the ones that have no cover.
     */
    private const SIMILAR_ASKED = 20;

    public function __construct(
        private readonly HardcoverClient $client,
        private readonly HardcoverPayload $payload,
        private readonly HardcoverAuthors $authors,
        private readonly HardcoverSeries $series,
        private readonly HardcoverBookPage $bookPage,
    ) {}

    public function code(): string
    {
        return $this->payload->code();
    }

    public function search(string $query, string $locale, MatureContent $mature): array
    {
        $data = $this->client->query(
            'query Search($query: String!) {
                search(query: $query, query_type: "books", per_page: '.self::PAGE_SIZE.', page: 1) { results }
            }',
            ['query' => $query],
            $this->payload->ttl('search'),
            requireKey: 'search.results.hits',
        );

        return $this->payload->documentSummaries($data);
    }

    public function bookByIsbns(array $isbns, string $locale): ?MediaSummary
    {
        $isbns = array_values(array_filter($isbns, fn (string $isbn): bool => $isbn !== ''));

        if ($isbns === []) {
            return null;
        }

        // Editions of one work all point back to the same book, so any ISBN of
        // the work lands on it — take the first edition that carries a book.
        $data = $this->client->query(
            'query ByIsbn($isbns: [String!]!) {
                editions(where: {isbn_13: {_in: $isbns}}, limit: 10) {
                    book { '.HardcoverPayload::SUMMARY_FIELDS.' }
                }
            }',
            ['isbns' => $isbns],
            $this->payload->ttl('details'),
        );

        foreach ((array) ($data['editions'] ?? []) as $edition) {
            $book = is_array($edition) ? ($edition['book'] ?? null) : null;

            if (is_array($book) && isset($book['id'])) {
                return $this->payload->summary($book);
            }
        }

        return null;
    }

    public function details(string $type, string $ref, string $locale, ?string $region = null): MediaDetails
    {
        return $this->bookPage->details($type, $ref, $locale);
    }

    public function similarTo(string $type, string $ref, string $locale): array
    {
        // The ids come with the book's answer, which the page has just fetched
        // and the cache still holds; only the cards cost a request of their own.
        return $this->cardsInOrder(array_slice($this->bookPage->similarIds($type, $ref), 0, self::SIMILAR_ASKED), $this->payload->ttl('similar'));
    }

    public function externalIdSources(): array
    {
        return [ExternalIdSource::Goodreads];
    }

    public function refForExternalId(string $type, ExternalIdSource $source, string $id): ?string
    {
        if ($type !== 'book' || $source !== ExternalIdSource::Goodreads) {
            return null;
        }

        // A Goodreads id names one edition; every edition of a work maps to the same book.
        $data = $this->client->query(
            'query ByGoodreads($id: String!) {
                book_mappings(where: {platform_id: {_eq: '.HardcoverPayload::GOODREADS_PLATFORM.'}, external_id: {_eq: $id}}, limit: 1) { book_id }
            }',
            ['id' => $id],
            $this->payload->ttl('details'),
        );

        $bookId = Payload::positiveInt(Payload::list($data['book_mappings'] ?? null)[0] ?? [], 'book_id');

        return $bookId === null ? null : (string) $bookId;
    }

    public function collection(string $ref, string $locale): CollectionDetails
    {
        return $this->series->collection($ref);
    }

    public function person(string $ref, string $locale, MatureContent $mature): PersonDetails
    {
        return $this->authors->person($ref, $locale);
    }

    public function trending(string $locale): array
    {
        // books_trending only serves ids ranked by recent reader activity;
        // a second query fills the summaries.
        $data = $this->client->query(
            'query Trending($from: date!, $to: date!) {
                books_trending(from: $from, to: $to, limit: '.self::TRENDING_ASKED.') { ids }
            }',
            ['from' => today()->subMonth()->toDateString(), 'to' => today()->toDateString()],
            $this->payload->ttl('listing'),
            requireKey: 'books_trending.ids',
        );

        $cards = $this->cardsInOrder($this->payload->bookIds(data_get($data, 'books_trending.ids')), $this->payload->ttl('listing'));

        return array_slice($cards, 0, self::TRENDING_SHOWN);
    }

    public function discoverableTypes(): array
    {
        return ['book'];
    }

    public function popular(string $type, string $locale, MatureContent $mature): array
    {
        $this->payload->assertBook($type);

        $data = $this->client->query(
            'query Popular {
                books(order_by: {users_count: desc_nulls_last}, limit: 30) { '.HardcoverPayload::SUMMARY_FIELDS.' }
            }',
            [],
            $this->payload->ttl('listing'),
            requireKey: 'books',
        );

        return $this->payload->bookSummaries($data);
    }

    public function upcoming(string $type, string $locale, MatureContent $mature): array
    {
        $this->payload->assertBook($type);

        // users_count > 10 keeps self-published datestuffing out of the
        // section — the analogue of IGDB's hypes filter.
        $data = $this->client->query(
            'query Upcoming($today: date!) {
                books(
                    where: {release_date: {_gte: $today}, users_count: {_gt: 10}},
                    order_by: {release_date: asc},
                    limit: 30
                ) { '.HardcoverPayload::SUMMARY_FIELDS.' }
            }',
            ['today' => today()->toDateString()],
            $this->payload->ttl('listing'),
            requireKey: 'books',
        );

        return $this->payload->bookSummaries($data);
    }

    public function genres(string $type, string $locale): array
    {
        $this->payload->assertBook($type);

        $data = $this->client->query(
            'query Genres {
                tags(
                    where: {tag_category: {category: {_eq: "Genre"}}, count: {_gt: 1000}},
                    order_by: {count: desc_nulls_last},
                    limit: 40
                ) { tag }
            }',
            [],
            $this->payload->ttl('listing'),
            requireKey: 'tags',
        );

        $genres = [];

        foreach ((array) ($data['tags'] ?? []) as $tag) {
            $name = is_array($tag) ? Payload::string($tag, 'tag') : null;

            if ($name !== null) {
                // The search index filters by genre NAME, so the name is the
                // ref — there is no stable numeric genre id in the index.
                $genres[] = new GenreOption($name, $name);
            }
        }

        return $genres;
    }

    public function byGenre(string $type, string $genreRef, string $locale, MatureContent $mature, int $page = 1): Paginated
    {
        $this->payload->assertBook($type);

        $page = max(1, $page);

        // Typesense filter syntax: backticks quote values with spaces or
        // punctuation; strip them from the input to avoid breaking out.
        $filter = 'genres:=`'.str_replace('`', '', $genreRef).'`';

        $data = $this->client->query(
            'query ByGenre($filter: String!, $page: Int!) {
                search(
                    query: "*", query_type: "books", per_page: '.self::PAGE_SIZE.', page: $page,
                    sort: "users_count:desc", filter_by: $filter
                ) { results }
            }',
            ['filter' => $filter, 'page' => $page],
            $this->payload->ttl('listing'),
            requireKey: 'search.results.hits',
        );

        $found = (array) data_get($data, 'search.results', []);
        $found = Payload::positiveInt($found, 'found') ?? 0;

        return new Paginated(
            // A genre shelf is curated: a book without a cover would break the even grid.
            items: array_values(array_filter($this->payload->documentSummaries($data), fn (MediaSummary $book): bool => $book->posterPath !== null)),
            page: $page,
            // Typesense stops serving results deep into a listing; 100 pages
            // of one genre is more than anyone browses anyway.
            totalPages: min(self::MAX_GENRE_PAGES, max(1, (int) ceil($found / self::PAGE_SIZE))),
            total: $found,
        );
    }

    /**
     * Cards of books known only by id (a trending ranking, a similar list),
     * in the order given; a book Hardcover no longer has, or one without a
     * cover, left out of the shelf.
     *
     * @param  list<int>  $ids
     * @return list<MediaSummary>
     */
    private function cardsInOrder(array $ids, int $ttl): array
    {
        if ($ids === []) {
            return [];
        }

        $books = $this->client->query(
            'query BooksByIds($ids: [Int!]!) {
                books(where: {id: {_in: $ids}}) { '.HardcoverPayload::SUMMARY_FIELDS.' }
            }',
            ['ids' => $ids],
            $ttl,
            requireKey: 'books',
        );

        // The books query loses the order asked; restore it.
        $byId = [];

        foreach (Payload::list($books['books'] ?? null) as $book) {
            $byId[Payload::positiveInt($book, 'id') ?? 0] = $book;
        }

        $summaries = [];

        foreach ($ids as $id) {
            if (isset($byId[$id]) && $this->payload->coverUrl($byId[$id]) !== null) {
                $summaries[] = $this->payload->summary($byId[$id]);
            }
        }

        return $summaries;
    }
}

<?php

namespace App\Catalog\Providers\Hardcover;

use App\Catalog\Data\CollectionDetails;
use App\Catalog\Data\CollectionLink;
use App\Catalog\Data\MediaSummary;
use App\Catalog\ItemNotFoundException;
use App\Support\Payload;
use App\Support\PlainText;

/**
 * A book's series (Harry Potter, Discworld) as a collection: its main
 * volumes in the series' own numbering. Companions (a novella at #1.5) and
 * unnumbered books stay off it, as Hardcover's `primary_books_count` leaves
 * them out of the count.
 */
final readonly class HardcoverSeries
{
    /** More volumes than any series has once companions are out. */
    private const PARTS_LIMIT = 100;

    /**
     * Hardcover's recipe for a series as its site shows it: merged books and
     * partial editions out, compilations out, one book per position (the most
     * read of its duplicates — each translation is a book of its own).
     */
    private const MEMBERS = 'book_series(
        distinct_on: position
        order_by: [{position: asc}, {book: {users_count: desc}}]
        where: {
            position: {_is_null: false}
            compilation: {_eq: false}
            book: {canonical_id: {_is_null: true}, is_partial_book: {_eq: false}}
        }
    )';

    public function __construct(
        private HardcoverClient $client,
        private HardcoverPayload $payload,
    ) {}

    /**
     * The series a book's page links to: the one Hardcover features on it,
     * when the book has a place in it. A book without a position belongs to a
     * loose set (a reading list), not a series one reads in order.
     *
     * @param  array<array-key, mixed>  $book
     */
    public function of(array $book): ?CollectionLink
    {
        $featured = $this->payload->featuredSeries($book);

        return $featured === null || $featured['id'] === null || $featured['position'] === null
            ? null
            : new CollectionLink($featured['id'], $featured['name']);
    }

    /** A series and its main volumes in reading order. */
    public function collection(string $ref): CollectionDetails
    {
        if (! ctype_digit($ref)) {
            throw new ItemNotFoundException("Hardcover has no series [{$ref}].");
        }

        $series = Payload::map($this->client->query(
            'query Series($id: Int!) {
                series_by_pk(id: $id) {
                    id name description
                    '.self::MEMBERS.' { position book { '.HardcoverPayload::SUMMARY_FIELDS.' } }
                }
            }',
            ['id' => (int) $ref],
            $this->payload->ttl('details'),
            requireKey: 'series_by_pk',
        )['series_by_pk'] ?? null);

        if ($series === []) {
            throw new ItemNotFoundException("Hardcover returned no series for [{$ref}].");
        }

        $parts = array_slice($this->volumes(Payload::list($series['book_series'] ?? null)), 0, self::PARTS_LIMIT);

        // A series of companions alone is a loose set, nothing to read in order.
        if ($parts === []) {
            throw new ItemNotFoundException("Hardcover series [{$ref}] has no numbered volumes.");
        }

        return new CollectionDetails(
            ref: $ref,
            name: Payload::text($series, 'name'),
            overview: PlainText::fromHtml($series['description'] ?? null),
            // Hardcover draws no art for a series: its first volume's cover stands in.
            posterPath: $parts[0]->posterPath,
            parts: $parts,
        );
    }

    /**
     * The books at whole-number positions (0 for a prequel counts), in the
     * order the query sorted them.
     *
     * @param  list<array<array-key, mixed>>  $members
     * @return list<MediaSummary>
     */
    private function volumes(array $members): array
    {
        $volumes = [];

        foreach ($members as $member) {
            $position = Payload::float($member, 'position');
            $book = Payload::map($member['book'] ?? null);

            if ($position !== null && floor($position) === $position && Payload::id($book['id'] ?? null) !== null) {
                $volumes[] = $this->payload->summary($book);
            }
        }

        return $volumes;
    }
}

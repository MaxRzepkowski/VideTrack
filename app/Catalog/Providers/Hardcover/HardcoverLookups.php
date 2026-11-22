<?php

namespace App\Catalog\Providers\Hardcover;

use App\Catalog\Data\MediaSummary;
use App\Support\Payload;

/**
 * Books asked about many at a time: by their Goodreads ids, and the cards of
 * books known by Hardcover's own id. Their callers name hundreds of books at
 * once (the works one run of ties finds), and every request spends one of
 * the sixty a minute Hardcover allows.
 */
final readonly class HardcoverLookups
{
    /** Ids in one request: hundreds of books' answers stay one small response. */
    private const ASKED_AT_ONCE = 100;

    public function __construct(
        private HardcoverClient $client,
        private HardcoverPayload $payload,
        private HardcoverEditions $editions,
    ) {}

    /**
     * A Goodreads id names one edition; every edition of a work maps to the same book.
     *
     * @param  list<string>  $ids
     * @return array<array-key, string> Goodreads id => book ref
     */
    public function byGoodreads(array $ids): array
    {
        $refs = [];

        foreach (array_chunk(array_values(array_unique($ids)), self::ASKED_AT_ONCE) as $chunk) {
            $data = $this->client->query(
                'query ByGoodreads($ids: [String!]!) {
                    book_mappings(where: {platform_id: {_eq: '.HardcoverPayload::GOODREADS_PLATFORM.'}, external_id: {_in: $ids}}) { external_id book_id }
                }',
                ['ids' => $chunk],
                $this->payload->ttl('details'),
            );

            foreach (Payload::list($data['book_mappings'] ?? null) as $mapping) {
                $id = Payload::string($mapping, 'external_id');
                $book = Payload::positiveInt($mapping, 'book_id');

                if ($id !== null && $book !== null) {
                    $refs[$id] ??= (string) $book;
                }
            }
        }

        return $refs;
    }

    /**
     * The cards a book's page would show for each, its reach included: in
     * the reader's edition, as the page names the book.
     *
     * @param  list<string>  $refs
     * @return array<array-key, MediaSummary> ref => card
     */
    public function summaries(array $refs, string $locale): array
    {
        // A ref from an import or a webhook may be anything; a bad one names nothing.
        $ids = array_values(array_unique(array_map(intval(...), array_filter($refs, ctype_digit(...)))));
        $cards = [];

        foreach (array_chunk($ids, self::ASKED_AT_ONCE) as $chunk) {
            $data = $this->client->query(
                'query BookCards($ids: [Int!]!'.$this->editions->declarations().') {
                    books(where: {id: {_in: $ids}}) {
                        '.HardcoverPayload::SUMMARY_FIELDS.' ratings_count pages
                        '.$this->editions->selection().'
                    }
                }',
                ['ids' => $chunk, ...$this->editions->variables()],
                $this->payload->ttl('details'),
                requireKey: 'books',
            );

            foreach (Payload::list($data['books'] ?? null) as $book) {
                $edition = $this->editions->byLocale($book)[$locale] ?? null;
                $card = $this->payload->summary($edition === null ? $book : $this->editions->asEdition($book, $edition));
                $cards[$card->ref] = $card;
            }
        }

        return $cards;
    }
}

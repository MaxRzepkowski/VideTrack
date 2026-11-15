<?php

namespace App\Catalog\Providers\Hardcover;

use App\Catalog\Data\PersonCredit;
use App\Catalog\Data\PersonDetails;
use App\Catalog\Department;
use App\Catalog\ItemNotFoundException;
use App\Support\Payload;
use App\Support\PlainText;

/**
 * An author's page: the record and the whole shelf, most-read first, each
 * book under the part the person had in it (wrote it, translated it…).
 */
final readonly class HardcoverAuthors
{
    /**
     * Rows per request on an author's shelf. Not a cap on the shelf — it is the
     * most Hardcover's role hands out at once, so a longer shelf is read page by
     * page (see authorBooks) until the author has none left.
     */
    private const AUTHOR_BOOKS_PER_REQUEST = 1000;

    /**
     * How librarians title an author's own site among their links ("Official
     * Site", "The Official Website of the JRR Tolkien Estate", or a bare
     * "Website"); a society or a fan page is titled by its own name.
     */
    private const OFFICIAL = '/official|^website$/i';

    public function __construct(
        private HardcoverClient $client,
        private HardcoverPayload $payload,
    ) {}

    public function person(string $ref, string $locale): PersonDetails
    {
        if (! ctype_digit($ref)) {
            throw new ItemNotFoundException("Hardcover does not serve an author for [{$ref}].");
        }

        // The books come from the `books` root, not from the author's nested
        // `contributions`: Hasura caps a nested relation at 100 rows and returns
        // them in no particular order, so a prolific author would silently lose
        // books — and Hardcover keeps a separate book row per translation, which
        // fills that cap with editions nobody reads. Filtering books by the author
        // instead means the page gets the whole shelf, most-read first.
        //
        // A book still ahead of us has no readers yet, so it sorts last; the
        // unreleased titles come as their own slice of the first request, which
        // is what the followed-author sweep announces.
        $payload = $this->client->query(
            'query Author($id: Int!, $today: date!) {
                authors_by_pk(id: $id) {
                    id name bio born_date death_date slug cached_image location links
                }
                books(
                    where: {contributions: {author_id: {_eq: $id}}}
                    order_by: [{users_count: desc}, {id: asc}]
                    limit: '.self::AUTHOR_BOOKS_PER_REQUEST.'
                ) { '.HardcoverPayload::SUMMARY_FIELDS.' users_count }
                upcoming: books(
                    where: {contributions: {author_id: {_eq: $id}}, release_date: {_gte: $today}}
                    order_by: {release_date: asc}
                    limit: '.self::AUTHOR_BOOKS_PER_REQUEST.'
                ) { '.HardcoverPayload::SUMMARY_FIELDS.' users_count }
            }',
            ['id' => (int) $ref, 'today' => today()->toDateString()],
            $this->payload->ttl('details'),
            requireKey: 'authors_by_pk',
        );

        $data = $payload['authors_by_pk'] ?? null;

        if (! is_array($data)) {
            throw new ItemNotFoundException("Hardcover returned no author for [{$ref}].");
        }

        $slug = Payload::string($data, 'slug');

        $credits = [];
        $seen = [];

        $rows = array_merge(
            $this->authorBooks((int) $ref, (array) ($payload['books'] ?? [])),
            (array) ($payload['upcoming'] ?? []),
        );

        foreach ($rows as $book) {
            $id = is_array($book) ? Payload::positiveInt($book, 'id') : null;

            if ($id === null || isset($seen[$id])) {
                continue;
            }

            $seen[$id] = true;
            $credits[] = new PersonCredit(
                media: $this->payload->summary($book),
                department: $this->partIn($book, $ref),
                role: null,
                // Readers, not stars: ratings cluster just above 4 and tie
                // constantly, while the reader count separates a book from
                // its translations.
                popularity: (float) (Payload::positiveInt($book, 'users_count') ?? 0),
            );
        }

        usort($credits, fn (PersonCredit $a, PersonCredit $b) => $b->popularity <=> $a->popularity);

        return new PersonDetails(
            ref: (string) (Payload::positiveInt($data, 'id') ?? 0),
            name: Payload::string($data, 'name') ?? '',
            profilePath: $this->payload->coverUrl($data),
            // Bios carry the same light HTML markup as book descriptions.
            biography: PlainText::fromHtml($data['bio'] ?? null),
            knownForDepartment: $this->knownFor($credits),
            birthday: Payload::string($data, 'born_date'),
            deathday: Payload::string($data, 'death_date'),
            origin: Payload::string($data, 'location'),
            externalLinks: array_filter([
                ...$this->links($data),
                'hardcover' => $slug === null ? null : "https://hardcover.app/authors/{$slug}",
            ]),
            credits: $credits,
        );
    }

    /**
     * The part the person had in a book, from the book's own credits: a
     * translator's shelf holds the books they translated, not wrote. One
     * credited twice (an author who also drew the pictures) keeps the book
     * among what they wrote; one the credits do not name is its author.
     *
     * @param  array<array-key, mixed>  $book
     */
    private function partIn(array $book, string $ref): Department
    {
        $parts = array_filter($this->payload->contributors($book), fn (array $contributor): bool => $contributor['id'] === $ref);
        $roles = array_values(array_filter(array_column($parts, 'role'), is_string(...)));

        return $parts === [] || count($roles) < count($parts) ? Department::Author : $this->payload->department($roles[0]);
    }

    /**
     * The part most of the shelf is under, which the page opens on: a writer
     * who also translated stays a writer.
     *
     * @param  list<PersonCredit>  $credits
     */
    private function knownFor(array $credits): ?Department
    {
        $counts = [];

        foreach ($credits as $credit) {
            $counts[$credit->department->value] = ($counts[$credit->department->value] ?? 0) + 1;
        }

        arsort($counts);

        return Department::tryFrom((string) array_key_first($counts));
    }

    /**
     * The author's own site and their Wikipedia article, out of the links
     * Hardcover keeps; fan sites and the rest stay off the page.
     *
     * @param  array<array-key, mixed>  $data
     * @return array<string, string> link kind => url
     */
    private function links(array $data): array
    {
        $links = [];

        foreach (Payload::list($data['links'] ?? null) as $link) {
            $url = Payload::string($link, 'url');
            $host = $url === null || ! str_starts_with($url, 'http') ? null : parse_url($url, PHP_URL_HOST);

            if ($url === null || ! is_string($host)) {
                continue;
            }

            $title = Payload::string($link, 'title');

            if ($host === 'wikipedia.org' || str_ends_with($host, '.wikipedia.org')) {
                $links['wikipedia'] ??= $url;
            } elseif ($title !== null && preg_match(self::OFFICIAL, $title) === 1) {
                $links['homepage'] ??= $url;
            }
        }

        return $links;
    }

    /**
     * The author's whole shelf. Hardcover's role hands out at most
     * AUTHOR_BOOKS_PER_REQUEST rows per root field, so the pages after the one
     * that rode along with the author record are fetched until a short page
     * says the shelf ended. The sort carries `id` as a tiebreaker: without it
     * the thousands of books tied at zero readers could reshuffle between
     * requests and a page boundary would drop one.
     *
     * @param  array<array-key, mixed>  $first  the first page, already in hand
     * @return list<mixed>
     */
    private function authorBooks(int $id, array $first): array
    {
        $rows = array_values($first);

        // The ceiling is a runaway stop, not a shelf cap: it sits far above the
        // longest shelf Hardcover has (the `Unknown` bucket, ~18k books), and is
        // only here so a provider that ignored `offset` could not loop forever.
        $ceiling = self::AUTHOR_BOOKS_PER_REQUEST * 50;

        while ($rows !== [] && count($rows) < $ceiling && count($rows) % self::AUTHOR_BOOKS_PER_REQUEST === 0) {
            $page = (array) ($this->client->query(
                'query AuthorBooks($id: Int!, $offset: Int!) {
                    books(
                        where: {contributions: {author_id: {_eq: $id}}}
                        order_by: [{users_count: desc}, {id: asc}]
                        limit: '.self::AUTHOR_BOOKS_PER_REQUEST.'
                        offset: $offset
                    ) { '.HardcoverPayload::SUMMARY_FIELDS.' users_count }
                }',
                ['id' => $id, 'offset' => count($rows)],
                $this->payload->ttl('details'),
            )['books'] ?? []);

            if ($page === []) {
                break;
            }

            $rows = array_merge($rows, array_values($page));
        }

        return $rows;
    }
}

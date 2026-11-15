<?php

namespace App\Catalog\Providers\Hardcover;

use App\Catalog\Data\CreditData;
use App\Catalog\Data\KeywordData;
use App\Catalog\Data\MediaDetails;
use App\Catalog\Department;
use App\Catalog\Isbn;
use App\Catalog\ItemNotFoundException;
use App\Support\Payload;
use App\Support\PlainText;
use LogicException;

/**
 * A book's page out of one `books_by_pk` answer: the details, who took part
 * in it and in what role, its edition's ISBN, its Goodreads page, and the
 * book as its reader's own edition names it (HardcoverEditions). The ids of
 * its similar books ride on the same answer, so the similar shelf costs no
 * second look at the book.
 */
final readonly class HardcoverBookPage
{
    /** Moods and content warnings a book shows. */
    private const TAGS_KEPT = 10;

    /** Genres a book shows; the tail is reader tagging noise. */
    private const GENRES_KEPT = 8;

    public function __construct(
        private HardcoverClient $client,
        private HardcoverPayload $payload,
        private HardcoverSeries $series,
        private HardcoverEditions $editions,
    ) {}

    public function details(string $type, string $ref, string $locale): MediaDetails
    {
        $book = $this->book($type, $ref);
        $editions = $this->editions->byLocale($book);
        $edition = $editions[$locale] ?? null;
        // The reader's own edition names the book and gives its cover; the rest is the book's.
        $data = $edition === null ? $book : $this->editions->asEdition($book, $edition);

        $tags = $this->payload->tagNames($data);
        $slug = Payload::string($data, 'slug');
        $poster = $this->payload->coverUrl($data);
        $goodreads = Payload::string(Payload::list($data['book_mappings'] ?? null)[0] ?? [], 'external_id');
        $ownTitle = Payload::string($book, 'title');

        return new MediaDetails(
            summary: $this->payload->summary($data),
            tagline: Payload::string($data, 'headline'),
            // Descriptions carry light HTML markup (<i>, <b>).
            overview: PlainText::fromHtml($data['description'] ?? null),
            genres: array_slice($tags['Genre'] ?? [], 0, self::GENRES_KEPT),
            keywords: array_map(
                fn (string $name): KeywordData => new KeywordData($name),
                array_slice($tags['Mood'] ?? [], 0, self::TAGS_KEPT),
            ),
            externalLinks: array_filter([
                'hardcover' => $slug === null ? null : "https://hardcover.app/books/{$slug}",
                'goodreads' => $goodreads === null ? null : 'https://www.goodreads.com/book/show/'.rawurlencode($goodreads),
            ]),
            collection: $this->series->of($data),
            extra: [
                'authorLinks' => $this->payload->authorLinks($data),
                // Average cover color computed by Hardcover — the book page
                // paints its hero with it (books have no backdrops).
                'coverColor' => $this->payload->coverColor($data),
                'pages' => Payload::positiveInt($data, 'pages'),
                'series' => $this->payload->series($data),
                'contentWarnings' => array_slice($tags['Content Warning'] ?? [], 0, self::TAGS_KEPT) ?: null,
                'ratingsCount' => Payload::positiveInt($data, 'ratings_count'),
                'readersCount' => Payload::positiveInt($data, 'users_count'),
                'nonfiction' => match (Payload::intEnum($data, 'literary_type_id', HardcoverLiteraryType::class)) {
                    HardcoverLiteraryType::Nonfiction => true,
                    HardcoverLiteraryType::Fiction => false,
                    null => null,
                },
                'isbn' => $this->isbn($data),
                'goodreadsId' => $goodreads,
                'originalTitle' => $ownTitle !== Payload::string($data, 'title') ? $ownTitle : null,
                'localEdition' => $edition === null ? null : [
                    'publisher' => Payload::string(Payload::map($edition['publisher'] ?? null), 'name'),
                    'year' => $this->editionYear($book, $edition),
                ],
            ],
            crew: $edition === null ? $this->crew($book) : $this->crewOfEdition($book, $edition),
            posters: array_filter([$poster]),
            localizedTitles: $this->editions->localizedTitles($book, $editions),
        );
    }

    /**
     * The year the reader's edition came out. Editions often carry the
     * book's own date copied over (a Polish Hobbit "of 1937"), so a year the
     * book itself has says nothing of the edition and is left out.
     *
     * @param  array<array-key, mixed>  $book
     * @param  array<array-key, mixed>  $edition
     */
    private function editionYear(array $book, array $edition): ?int
    {
        $edited = $this->payload->year($edition);

        return $edited === $this->payload->year($book) ? null : $edited;
    }

    /**
     * Who made the reader's edition what it is: its own translator and
     * narrator — the book's are those of another language's edition — beside
     * the book's illustrator, editor and the rest.
     *
     * @param  array<array-key, mixed>  $book
     * @param  array<array-key, mixed>  $edition
     * @return list<CreditData>
     */
    private function crewOfEdition(array $book, array $edition): array
    {
        $ofLanguage = [Department::Translation, Department::Narration];

        return [
            ...array_filter($this->crew($book), fn (CreditData $credit): bool => ! in_array($credit->department, $ofLanguage, true)),
            ...array_filter($this->crew($edition), fn (CreditData $credit): bool => in_array($credit->department, $ofLanguage, true)),
        ];
    }

    /**
     * The ids of the books most like this one, the most similar first.
     *
     * @return list<int>
     */
    public function similarIds(string $type, string $ref): array
    {
        $ids = $this->payload->bookIds($this->book($type, $ref)['cached_similar_book_ids'] ?? null);

        return array_values(array_filter($ids, fn (int $id): bool => $id !== (int) $ref));
    }

    /**
     * One book's answer, as its page and its similar books both read it.
     *
     * @return array<array-key, mixed>
     */
    private function book(string $type, string $ref): array
    {
        // The type registry routes only its own type here; anything else is a bug.
        if ($type !== 'book') {
            throw new LogicException("Hardcover does not serve details for type [{$type}].");
        }

        // A ref from an import or a webhook may be anything; a bad one names nothing.
        if (! ctype_digit($ref)) {
            throw new ItemNotFoundException("Hardcover has no book [{$ref}].");
        }

        $data = $this->client->query(
            'query Details($id: Int!'.$this->editions->declarations().') {
                books_by_pk(id: $id) {
                    id title subtitle headline description pages release_date release_year
                    rating ratings_count users_count slug compilation book_category_id literary_type_id
                    cached_image cached_contributors cached_featured_series cached_tags
                    cached_similar_book_ids
                    default_physical_edition { isbn_13 isbn_10 }
                    default_ebook_edition { isbn_13 isbn_10 }
                    book_mappings(where: {platform_id: {_eq: '.HardcoverPayload::GOODREADS_PLATFORM.'}}, order_by: {id: asc}, limit: 1) { external_id }
                    '.$this->editions->selection().'
                }
            }',
            ['id' => (int) $ref, ...$this->editions->variables()],
            $this->payload->ttl('details'),
            requireKey: 'books_by_pk',
        )['books_by_pk'] ?? null;

        if (! is_array($data)) {
            throw new ItemNotFoundException("Hardcover returned no book for [{$ref}].");
        }

        return $data;
    }

    /**
     * Everyone credited beside the authors — translator, illustrator,
     * narrator, editor — in their part (`BookRole`, else Hardcover's own
     * word); one without a page of their own links nowhere, so is left out.
     *
     * @param  array<array-key, mixed>  $data
     * @return list<CreditData>
     */
    private function crew(array $data): array
    {
        $crew = [];

        foreach ($this->payload->contributors($data) as $contributor) {
            if ($contributor['role'] === null || $contributor['id'] === null) {
                continue;
            }

            $crew[] = new CreditData(
                personRef: $contributor['id'],
                provider: $this->payload->code(),
                name: $contributor['name'],
                profilePath: null,
                department: $this->payload->department($contributor['role']),
                role: $this->payload->bookRole($contributor['role'])->value ?? $contributor['role'],
                order: count($crew),
            );
        }

        return $crew;
    }

    /**
     * The ISBN-13 of the edition Hardcover shows for the book: its printed
     * one, else its e-book.
     *
     * @param  array<array-key, mixed>  $data
     */
    private function isbn(array $data): ?string
    {
        foreach (['default_physical_edition', 'default_ebook_edition'] as $key) {
            $edition = Payload::map($data[$key] ?? null);

            foreach (['isbn_13', 'isbn_10'] as $field) {
                $written = Payload::string($edition, $field);
                $isbn = $written === null ? null : Isbn::thirteen($written);

                if ($isbn !== null) {
                    return $isbn;
                }
            }
        }

        return null;
    }
}

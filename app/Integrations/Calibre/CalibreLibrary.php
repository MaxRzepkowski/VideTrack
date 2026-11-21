<?php

namespace App\Integrations\Calibre;

use App\Integrations\Calibre\Data\CalibreBook;
use App\Integrations\Import\UploadedSqlite;
use App\Support\Numbers;
use App\Support\Payload;
use Generator;
use PDO;
use Pdo\Sqlite;
use UnexpectedValueException;

/**
 * A Calibre library's `metadata.db`, opened as any uploaded database is
 * ({@see UploadedSqlite}). Calibre keeps its catalog in SQLite with a
 * schema of its own, so this is the one place that knows the tables: a
 * book, its authors in their order, its identifiers by type, its rating.
 * The file comes from the user, so its values are read like any other
 * export's.
 */
final readonly class CalibreLibrary
{
    /** The tables a Calibre library has and this reads, with the columns read from each. */
    private const TABLES = [
        'books' => ['id', 'title', 'timestamp', 'pubdate'],
        'authors' => ['id', 'name'],
        'books_authors_link' => ['id', 'book', 'author'],
        'identifiers' => ['book', 'type', 'val'],
        'ratings' => ['id', 'rating'],
        'books_ratings_link' => ['book', 'rating'],
    ];

    /** The year Calibre writes for a book whose publication date nobody set. */
    private const NO_YEAR = 101;

    private const BOOKS = <<<'SQL'
        SELECT b.id, b.title, b.timestamp AS added, b.pubdate AS published,
            (SELECT a.name FROM books_authors_link l JOIN authors a ON a.id = l.author WHERE l.book = b.id ORDER BY l.id LIMIT 1) AS author,
            (SELECT i.val FROM identifiers i WHERE i.book = b.id AND i.type = 'isbn' LIMIT 1) AS isbn,
            (SELECT i.val FROM identifiers i WHERE i.book = b.id AND i.type = 'goodreads' LIMIT 1) AS goodreads,
            (SELECT r.rating FROM books_ratings_link l JOIN ratings r ON r.id = l.rating WHERE l.book = b.id LIMIT 1) AS rating
        FROM books b
        ORDER BY b.id
        SQL;

    private function __construct(private Sqlite $db) {}

    /** The library in the file; null when it is no SQLite database or not Calibre's. */
    public static function open(string $path): ?self
    {
        $db = UploadedSqlite::open($path, self::TABLES);

        return $db === null ? null : new self($db);
    }

    public function count(): int
    {
        $count = $this->db->query('SELECT COUNT(*) FROM books');

        return $count === false ? 0 : Numbers::int($count->fetchColumn()) ?? 0;
    }

    /**
     * Every book, one at a time.
     *
     * @return Generator<int, CalibreBook>
     */
    public function books(): Generator
    {
        $books = $this->db->query(self::BOOKS);

        if ($books === false) {
            return;
        }

        while (is_array($row = $books->fetch(PDO::FETCH_ASSOC))) {
            $published = Payload::instant(Payload::string($row, 'published'));

            yield new CalibreBook(
                id: Payload::int($row, 'id') ?? throw new UnexpectedValueException('A Calibre book without its id.'),
                title: trim(Payload::text($row, 'title')),
                author: Payload::string($row, 'author'),
                isbn: Payload::string($row, 'isbn'),
                goodreads: Payload::string($row, 'goodreads'),
                rating: Payload::int($row, 'rating'),
                added: Payload::instant(Payload::string($row, 'added')),
                year: $published === null || $published->year <= self::NO_YEAR ? null : $published->year,
            );
        }
    }
}

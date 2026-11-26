<?php

namespace App\Integrations\KOReader;

use App\Integrations\Import\UploadedSqlite;
use App\Integrations\KOReader\Data\KOReaderBook;
use App\Support\Payload;
use Generator;
use PDO;
use Pdo\Sqlite;

/**
 * KOReader's `statistics.sqlite3`, opened read-only: a `book` row per book
 * and a `page_stat_data` row per page read, with when it was read and how
 * many pages the book had in the layout of that reading (a page number
 * means nothing without it: the count changes with the font). KOReader
 * keeps the file in WAL mode; it is opened as any uploaded database is
 * ({@see UploadedSqlite}).
 */
final readonly class KOReaderStatistics
{
    /** The tables this reads, and the columns of each it reads. */
    private const TABLES = [
        'book' => ['id', 'title', 'authors'],
        'page_stat_data' => ['id_book', 'page', 'start_time', 'total_pages'],
    ];

    /** How far into a book a page has to be for the book to count as read to its end. */
    private const END_SHARE = 0.95;

    private const BOOKS = <<<'SQL'
        SELECT b.title, b.authors,
            (SELECT MIN(p.start_time) FROM page_stat_data p WHERE p.id_book = b.id) AS started,
            (SELECT MAX(p.start_time) FROM page_stat_data p WHERE p.id_book = b.id AND p.total_pages > 0 AND p.page >= p.total_pages * :share) AS finished
        FROM book b
        ORDER BY b.id
        SQL;

    private function __construct(private Sqlite $db) {}

    /** The statistics in the file; null when it is no SQLite database or not KOReader's. */
    public static function open(string $path): ?self
    {
        $db = UploadedSqlite::open($path, self::TABLES);

        return $db === null ? null : new self($db);
    }

    /**
     * Every book, one at a time.
     *
     * @return Generator<int, KOReaderBook>
     */
    public function books(): Generator
    {
        $books = $this->db->prepare(self::BOOKS);
        $books->execute(['share' => self::END_SHARE]);

        while (is_array($row = $books->fetch(PDO::FETCH_ASSOC))) {
            yield new KOReaderBook(
                title: Payload::text($row, 'title'),
                author: $this->firstAuthor(Payload::text($row, 'authors')),
                started: Payload::epochSeconds($row['started'] ?? null),
                finished: Payload::epochSeconds($row['finished'] ?? null),
            );
        }
    }

    /** KOReader keeps a book's authors one to a line, as its metadata lists them. */
    private function firstAuthor(string $authors): ?string
    {
        $first = trim(explode("\n", $authors)[0]);

        return $first === '' ? null : $first;
    }
}

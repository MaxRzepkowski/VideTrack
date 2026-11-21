<?php

namespace App\Integrations\Import;

use App\Support\Payload;
use PDO;
use Pdo\Sqlite;
use PDOException;

/**
 * A SQLite database a user uploaded (a Calibre library, an e-reader's
 * statistics), opened so that reading it can neither change it nor be led
 * elsewhere: read-only and immutable, which also keeps SQLite from looking
 * for a -wal or leaving a -shm beside the upload, and only when the tables
 * read are plain tables (not views or virtual tables standing in for them)
 * with every column read from them.
 */
final class UploadedSqlite
{
    /** What every SQLite file opens with. */
    private const HEADER = "SQLite format 3\0";

    /**
     * The database; null when the file is none, or lacks a table or a column.
     *
     * @param  array<string, list<string>>  $tables  table => the columns read from it
     */
    public static function open(string $path, array $tables): ?Sqlite
    {
        $head = is_file($path) ? file_get_contents($path, length: strlen(self::HEADER)) : false;

        if ($head !== self::HEADER) {
            return null;
        }

        try {
            $db = new Sqlite('sqlite:file:'.implode('/', array_map(rawurlencode(...), explode('/', $path))).'?immutable=1', options: [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                Sqlite::ATTR_OPEN_FLAGS => Sqlite::OPEN_READONLY,
            ]);

            foreach ($tables as $table => $columns) {
                if (! self::hasTable($db, $table, $columns)) {
                    return null;
                }
            }

            return $db;
        } catch (PDOException) {
            return null;
        }
    }

    /**
     * @param  list<string>  $columns
     */
    private static function hasTable(Sqlite $db, string $table, array $columns): bool
    {
        $found = $db->prepare("SELECT sql FROM sqlite_master WHERE type = 'table' AND name = ?");
        $found->execute([$table]);
        $sql = $found->fetchColumn();

        if (! is_string($sql) || str_starts_with(strtoupper(ltrim($sql)), 'CREATE VIRTUAL')) {
            return false;
        }

        $info = $db->prepare('SELECT name FROM pragma_table_info(?)');
        $info->execute([$table]);

        return array_diff($columns, Payload::strings($info->fetchAll(PDO::FETCH_COLUMN))) === [];
    }
}

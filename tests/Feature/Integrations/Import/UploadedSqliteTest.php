<?php

use App\Integrations\Import\UploadedSqlite;
use Pdo\Sqlite;

/** A database made of these statements, at a path of its own; the path. */
function uploadedDatabase(string $sql): string
{
    $path = scratchDirectory('sqlite').'/upload.db';
    (new Sqlite("sqlite:{$path}"))->exec($sql);

    return $path;
}

const UPLOADED_TABLES = ['book' => ['id', 'title'], 'page' => ['id_book', 'number']];

test('a database with the tables and their columns opens, newer columns and tables beside them too', function () {
    $path = uploadedDatabase('CREATE TABLE book (id integer, title text, colour text); CREATE TABLE page (id_book integer, number integer); CREATE TABLE extra (x text); INSERT INTO book VALUES (1, \'Dune\', \'red\')');

    $db = UploadedSqlite::open($path, UPLOADED_TABLES);

    expect($db?->query('SELECT title FROM book')?->fetchColumn())->toBe('Dune');
});

test('a table missing, a column missing, a view or a virtual table standing in for one is refused', function (string $sql) {
    expect(UploadedSqlite::open(uploadedDatabase($sql), UPLOADED_TABLES))->toBeNull();
})->with([
    'a table missing' => ['CREATE TABLE book (id integer, title text)'],
    'a column missing' => ['CREATE TABLE book (id integer); CREATE TABLE page (id_book integer, number integer)'],
    'a view' => ['CREATE TABLE secrets (title text); CREATE VIEW book AS SELECT 1 AS id, title FROM secrets; CREATE TABLE page (id_book integer, number integer)'],
    'a virtual table' => ['CREATE VIRTUAL TABLE book USING fts5(id, title); CREATE TABLE page (id_book integer, number integer)'],
]);

test('a file that is no database, or only begins like one, is refused', function () {
    $text = scratchPath('text', '.db');
    file_put_contents($text, 'title,author');
    $truncated = scratchPath('truncated', '.db');
    file_put_contents($truncated, "SQLite format 3\0broken");

    expect(UploadedSqlite::open($text, UPLOADED_TABLES))->toBeNull()
        ->and(UploadedSqlite::open($truncated, UPLOADED_TABLES))->toBeNull()
        ->and(UploadedSqlite::open(scratchPath('missing', '.db'), UPLOADED_TABLES))->toBeNull();
});

test('a database kept in WAL mode is read without leaving files beside the upload, nor written to', function () {
    $path = uploadedDatabase('PRAGMA journal_mode=WAL; CREATE TABLE book (id integer, title text); CREATE TABLE page (id_book integer, number integer); INSERT INTO book VALUES (1, \'Dune\')');
    $directory = dirname($path);
    array_map(unlink(...), array_diff(glob("{$directory}/*") ?: [], [$path]));
    $before = hash_file('xxh128', $path);

    $db = UploadedSqlite::open($path, UPLOADED_TABLES);

    expect($db?->query('SELECT COUNT(*) FROM book')?->fetchColumn())->toBe(1)
        ->and(fn () => $db?->exec('DELETE FROM book'))->toThrow(PDOException::class);

    unset($db);

    expect(glob("{$directory}/*"))->toBe([$path])
        ->and(hash_file('xxh128', $path))->toBe($before);
});

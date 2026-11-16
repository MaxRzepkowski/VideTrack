<?php

namespace App\Integrations\Trakt;

use App\Integrations\Import\ImportCategory;
use App\Integrations\Import\UnpackedFileLimit;
use App\Integrations\Import\ZipExport;
use App\Support\Payload;
use Generator;

/**
 * Read-only view over a Trakt.tv data export (a ZIP of JSON files at the
 * archive root). Files are read one at a time straight from the archive —
 * the export is never extracted to disk — and each relevant file is a JSON
 * array whose elements this class yields lazily. Consumers (the manifest
 * endpoint and {@see TraktZipSource}) translate Trakt's nested
 * shape into provider coordinates themselves.
 */
final class TraktArchive
{
    private readonly ZipExport $zip;

    /** @var list<array{trakt_id: int, name: string, item_count: int, is_public: bool}>|null */
    private ?array $lists = null;

    public function __construct(string $path)
    {
        $this->zip = new ZipExport($path);
    }

    /**
     * A real Trakt export always carries the user profile file — a cheap
     * marker that tells this apart from an arbitrary ZIP.
     */
    public function isValid(): bool
    {
        return $this->zip->has('user-profile.json');
    }

    /**
     * Whether a file in the archive unpacks past what an import decodes.
     */
    public function isTooLarge(): bool
    {
        foreach ($this->zip->names() as $name) {
            if ($this->unpackedTooLarge($name)) {
                return true;
            }
        }

        return false;
    }

    private function unpackedTooLarge(string $name): bool
    {
        return ($this->zip->size($name) ?? 0) > UnpackedFileLimit::bytes();
    }

    /**
     * Every entry of a category, streamed across its (possibly paginated)
     * files.
     *
     * @return Generator<int, array<array-key, mixed>>
     */
    public function category(ImportCategory $category): Generator
    {
        yield from $this->entries(match ($category) {
            ImportCategory::History => '/^watched-history-.*\.json$/',
            ImportCategory::Ratings => '/^ratings-.*\.json$/',
            ImportCategory::Watchlist => '/^lists-watchlist\.json$/',
            ImportCategory::Favorites => '/^lists-favorites\.json$/',
        });
    }

    /**
     * Entries of one custom list, gathered across its pages. The list id sits
     * right after `lists-list-`, so the prefix match is unambiguous — no page
     * suffix to parse.
     *
     * @return Generator<int, array<array-key, mixed>>
     */
    public function listItems(int $traktId): Generator
    {
        yield from $this->entries('/^lists-list-'.$traktId.'-.*\.json$/');
    }

    public function count(ImportCategory $category): int
    {
        $count = 0;

        foreach ($this->category($category) as $_) {
            $count++;
        }

        return $count;
    }

    /**
     * Custom-list metadata from `lists-lists.json` (name, size, privacy),
     * cached — item counts come from here so the manifest never has to open
     * the per-list files.
     *
     * @return list<array{trakt_id: int, name: string, item_count: int, is_public: bool}>
     */
    public function lists(): array
    {
        if ($this->lists !== null) {
            return $this->lists;
        }

        $lists = [];

        foreach ($this->decode('lists-lists.json') as $list) {
            $id = Payload::int(Payload::map($list['ids'] ?? null), 'trakt');

            if ($id === null) {
                continue;
            }

            $lists[] = [
                'trakt_id' => $id,
                'name' => is_string($list['name'] ?? null) && $list['name'] !== '' ? $list['name'] : "List {$id}",
                'item_count' => is_int($list['item_count'] ?? null) ? $list['item_count'] : 0,
                'is_public' => ($list['privacy'] ?? null) === 'public',
            ];
        }

        return $this->lists = $lists;
    }

    /**
     * @return array{trakt_id: int, name: string, item_count: int, is_public: bool}|null
     */
    public function listMeta(int $traktId): ?array
    {
        foreach ($this->lists() as $list) {
            if ($list['trakt_id'] === $traktId) {
                return $list;
            }
        }

        return null;
    }

    /**
     * Yields every top-level element of every archive file whose name matches
     * the pattern; malformed files and non-array elements are skipped.
     *
     * @return Generator<int, array<array-key, mixed>>
     */
    private function entries(string $pattern): Generator
    {
        foreach ($this->zip->names() as $name) {
            $raw = preg_match($pattern, $name) === 1 && ! $this->unpackedTooLarge($name) ? $this->zip->contents($name) : null;

            foreach (Payload::list($raw === null ? null : json_decode($raw, true)) as $entry) {
                yield $entry;
            }
        }
    }

    /**
     * The decoded top-level array of a single named file, or an empty list.
     *
     * @return list<array<array-key, mixed>>
     */
    private function decode(string $name): array
    {
        $raw = $this->unpackedTooLarge($name) ? null : $this->zip->contents($name);

        return Payload::list($raw === null ? null : json_decode($raw, true));
    }
}

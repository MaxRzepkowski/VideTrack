<?php

namespace App\Integrations\Trakt;

use App\Support\Payload;
use Generator;
use ZipArchive;

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
    /** Category key => regex matching the export files that carry it. */
    private const CATEGORY_FILES = [
        'history' => '/^watched-history-.*\.json$/',
        'ratings' => '/^ratings-.*\.json$/',
        'watchlist' => '/^lists-watchlist\.json$/',
        'favorites' => '/^lists-favorites\.json$/',
    ];

    private readonly ZipArchive $zip;

    private readonly bool $opened;

    /** @var list<array{trakt_id: int, name: string, item_count: int, is_public: bool}>|null */
    private ?array $lists = null;

    public function __construct(string $path)
    {
        $this->zip = new ZipArchive;
        $this->opened = $this->zip->open($path) === true;
    }

    /**
     * A real Trakt export always carries the user profile file — a cheap
     * marker that tells this apart from an arbitrary ZIP.
     */
    public function isValid(): bool
    {
        return $this->opened && $this->zip->locateName('user-profile.json') !== false;
    }

    /**
     * Whether a file in the archive unpacks past what an import decodes.
     */
    public function isTooLarge(): bool
    {
        if (! $this->opened) {
            return false;
        }

        for ($index = 0; $index < $this->zip->numFiles; $index++) {
            if ($this->unpackedTooLarge($index)) {
                return true;
            }
        }

        return false;
    }

    private function unpackedTooLarge(int $index): bool
    {
        $stat = $this->zip->statIndex($index);

        return $stat === false || $stat['size'] > config()->integer('tracker.imports.max_json_megabytes') * 1024 * 1024;
    }

    /**
     * Every entry of a category, streamed across its (possibly paginated)
     * files.
     *
     * @return Generator<int, array<array-key, mixed>>
     */
    public function category(string $category): Generator
    {
        yield from $this->entries(self::CATEGORY_FILES[$category] ?? '/^$/');
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

    public function count(string $category): int
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
        if (! $this->opened) {
            return;
        }

        for ($index = 0; $index < $this->zip->numFiles; $index++) {
            $name = $this->zip->getNameIndex($index);

            if ($name === false || preg_match($pattern, $name) !== 1 || $this->unpackedTooLarge($index)) {
                continue;
            }

            $raw = $this->zip->getFromIndex($index);

            if ($raw === false) {
                continue;
            }

            foreach (Payload::list(json_decode($raw, true)) as $entry) {
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
        if (! $this->opened) {
            return [];
        }

        $index = $this->zip->locateName($name);

        if ($index === false || $this->unpackedTooLarge($index)) {
            return [];
        }

        $raw = $this->zip->getFromIndex($index);

        if ($raw === false) {
            return [];
        }

        return Payload::list(json_decode($raw, true));
    }
}

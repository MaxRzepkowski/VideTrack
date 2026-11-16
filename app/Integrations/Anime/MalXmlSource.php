<?php

namespace App\Integrations\Anime;

use App\Integrations\Import\Contracts\ReadsFile;
use App\Integrations\Import\Data\ImportInput;
use App\Integrations\Import\Data\ImportManifest;
use App\Integrations\Import\Data\SetupNote;
use App\Integrations\Import\Rows\SkipRow;
use App\Integrations\Import\UnpackedFileLimit;
use App\Support\Payload;
use Carbon\CarbonImmutable;
use LogicException;
use XMLReader;

/**
 * A list in MyAnimeList's XML export format: `<myanimelist>` holding either
 * `<anime>` or `<manga>` entries, plain or gzipped as MyAnimeList hands it
 * out (`compress.zlib://` reads both). The file keeps no moment of the
 * entry's last change, so what has no day of its own lands as any undated
 * import does.
 *
 * Read as a stream, without the network and without expanding entities: an
 * uploaded file names no other file.
 */
final class MalXmlSource extends AnimeListSource implements ReadsFile
{
    public const CODE = 'mal-xml';

    private const ROOT = 'myanimelist';

    /** How much of the file is read at a time while measuring it unpacked, in bytes. */
    private const MEASURE_CHUNK = 1024 * 1024;

    /** @var array{path: string, entries: list<AnimeListEntry|SkipRow>|null}|null */
    private ?array $read = null;

    public function code(): string
    {
        return self::CODE;
    }

    public function label(): string
    {
        return __('import.myanimelist_export_xml');
    }

    public function description(): string
    {
        return __('import.an_anime_or_manga_list_in');
    }

    public function taskLabel(): string
    {
        return __('import.import_from_myanimelist');
    }

    public function accept(): string
    {
        return '.xml,.gz';
    }

    public function notes(): array
    {
        return [new SetupNote(__('import.on_myanimelist_open_export'), 'https://myanimelist.net/panel.php?go=export')];
    }

    public function analyze(ImportInput $input): ImportManifest
    {
        $path = (string) $input->path;

        if ($this->unpacksTooLarge($path)) {
            return ImportManifest::invalid(__('import.a_file_in_this_export_is'));
        }

        $entries = $this->read($path);

        return $entries === null
            ? ImportManifest::invalid(__('import.the_file_is_not_a_myanimelist'))
            : $this->manifest($entries);
    }

    protected function entries(ImportInput $input): array
    {
        return $this->read((string) $input->path) ?? throw new LogicException('A MyAnimeList file read for rows that its analysis refused.');
    }

    /**
     * Whether the file is past the import limit once unpacked: a list is
     * held whole, and a gzip of a few megabytes can unpack to gigabytes.
     */
    private function unpacksTooLarge(string $path): bool
    {
        $limit = UnpackedFileLimit::bytes();
        $stream = is_file($path) ? fopen('compress.zlib://'.$path, 'rb') : false;
        $size = 0;

        if ($stream === false) {
            return false;
        }

        try {
            while ($size <= $limit && ($chunk = fread($stream, self::MEASURE_CHUNK)) !== false && $chunk !== '') {
                $size += strlen($chunk);
            }
        } finally {
            fclose($stream);
        }

        return $size > $limit;
    }

    /**
     * The file's entries, read once per file; null when it is not a
     * MyAnimeList export or breaks off.
     *
     * @return list<AnimeListEntry|SkipRow>|null
     */
    private function read(string $path): ?array
    {
        if ($this->read === null || $this->read['path'] !== $path) {
            $this->read = ['path' => $path, 'entries' => $this->parse($path)];
        }

        return $this->read['entries'];
    }

    /**
     * @return list<AnimeListEntry|SkipRow>|null
     */
    private function parse(string $path): ?array
    {
        $previous = libxml_use_internal_errors(true);
        $reader = new XMLReader;

        try {
            if (! $reader->open('compress.zlib://'.$path, null, LIBXML_NONET) || ! $this->opensWithRoot($reader)) {
                return null;
            }

            $entries = [];
            $more = $reader->read();

            while ($more) {
                if ($reader->nodeType !== XMLReader::ELEMENT || $reader->depth !== 1) {
                    $more = $reader->read();

                    continue;
                }

                // <myinfo>, the user's totals, is nothing of the list itself.
                $medium = ListMedium::tryFrom($reader->name);

                if ($medium !== null) {
                    $entries[] = $this->entry($medium, $this->fields($reader), count($entries) + 1);
                }

                $more = $reader->next();
            }

            // A file that breaks off reads no further, and silently so:
            // what it held is not what it says.
            return libxml_get_errors() === [] ? $entries : null;
        } finally {
            $reader->close();
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    private function opensWithRoot(XMLReader $reader): bool
    {
        while ($reader->read()) {
            if ($reader->nodeType === XMLReader::ELEMENT) {
                return $reader->name === self::ROOT;
            }
        }

        return false;
    }

    /**
     * The entry's child elements by name, as text (CDATA included), read up
     * to the entry's end. Read node by node rather than expanded: expanding
     * a file that breaks off inside an entry warns instead of failing.
     *
     * @return array<string, string>
     */
    private function fields(XMLReader $reader): array
    {
        $fields = [];
        $depth = $reader->depth;
        $field = null;

        if ($reader->isEmptyElement) {
            return $fields;
        }

        while ($reader->read() && $reader->depth > $depth) {
            if ($reader->nodeType === XMLReader::ELEMENT && $reader->depth === $depth + 1) {
                $field = $reader->name;
                $fields[$field] = '';
            } elseif ($field !== null && in_array($reader->nodeType, [XMLReader::TEXT, XMLReader::CDATA], true)) {
                $fields[$field] .= $reader->value;
            }
        }

        return array_map(trim(...), $fields);
    }

    /**
     * An entry the file gives no id or no status MyAnimeList writes comes
     * back as the reason, named by its title, or by its place in the file
     * when it has none.
     *
     * @param  array<string, string>  $fields
     */
    private function entry(ListMedium $medium, array $fields, int $position): AnimeListEntry|SkipRow
    {
        $anime = $medium === ListMedium::Anime;
        $id = Payload::positiveInt($fields, $anime ? 'series_animedb_id' : 'manga_mangadb_id');
        $title = Payload::string($fields, $anime ? 'series_title' : 'manga_title');
        $status = MalXmlStatus::read(Payload::text($fields, 'my_status'));

        if ($id === null) {
            return new SkipRow('import.skip_mal_entry_without_id', ['label' => $title ?? "#{$position}"]);
        }

        if ($status === null) {
            return new SkipRow('import.skip_mal_entry_unknown_status', ['label' => AnimeListEntry::label($title ?? "#{$position}", $id)]);
        }

        return new AnimeListEntry(
            $medium,
            $id,
            $title,
            $status->listStatus(),
            Payload::positiveInt($fields, 'my_score'),
            Payload::positiveInt($fields, $anime ? 'my_watched_episodes' : 'my_read_chapters') ?? 0,
            Payload::positiveInt($fields, $anime ? 'series_episodes' : 'manga_chapters'),
            $this->date($fields['my_start_date'] ?? null),
            $this->date($fields['my_finish_date'] ?? null),
        );
    }

    /**
     * MyAnimeList writes an unknown day as 0000-00-00, and a day the user
     * knew only in part with zeros for the rest: 2020-03-00, 2020-00-00. A
     * part known is read from its first day; a day no calendar has is none.
     */
    private function date(?string $value): ?CarbonImmutable
    {
        if ($value === null || preg_match('#^(\d{4})-(\d{2})-(\d{2})$#', $value, $parts) !== 1 || $parts[1] === '0000') {
            return null;
        }

        [, $year, $month, $day] = $parts;

        return Payload::instant($month === '00' ? "{$year}-01-01" : "{$year}-{$month}-".($day === '00' ? '01' : $day));
    }
}

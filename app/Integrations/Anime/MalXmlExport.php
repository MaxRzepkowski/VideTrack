<?php

namespace App\Integrations\Anime;

use App\Accounts\UserCalendar;
use App\Integrations\Export\ExportFormat;
use App\Integrations\Export\ExportShelf;
use App\Integrations\Export\ListStatus;
use App\Models\User;
use XMLWriter;

/**
 * One list in MyAnimeList's XML export format, the file MyAnimeList and the
 * trackers that read its exports import: a file holds either the anime or
 * the manga list, never both, so each is a download of its own. Every tag
 * MyAnimeList writes is written, filled with its own "none" where the
 * library keeps nothing of the kind (storage, tags, rewatches), because
 * importers expect the full entry. Days are the user's own calendar days.
 *
 * Read back by {@see MalXmlSource}.
 */
abstract class MalXmlExport implements ExportFormat
{
    /** `update_on_import`: an entry the user already has on the other side is left as it is there. */
    protected const KEEP_EXISTING = 0;

    /** The element the list's owner and totals stand in, ahead of the entries. */
    private const HEADER = 'myinfo';

    /** How MyAnimeList writes a day it does not know. */
    private const NO_DAY = '0000-00-00';

    /** The element each entry of the list is. */
    abstract protected function entryElement(): string;

    /** `user_export_type`: which of MyAnimeList's two lists the file is. */
    abstract protected function exportType(): int;

    /**
     * The header's counts in this list's words.
     *
     * @param  array<value-of<ListStatus>, int>  $counts  entries per status
     * @return array<string, int>
     */
    abstract protected function totals(int $entries, array $counts): array;

    /**
     * One entry's tags, in MyAnimeList's order.
     *
     * @return array<string, string|int>
     */
    abstract protected function fields(AnimeListEntry $entry, string $startedOn, string $finishedOn): array;

    /**
     * @return list<AnimeListEntry>
     */
    abstract protected function entries(User $user): array;

    public function shelf(): ExportShelf
    {
        return ExportShelf::Service;
    }

    public function part(): string
    {
        return $this->code();
    }

    public function extension(): string
    {
        return 'xml';
    }

    public function contentType(): string
    {
        return 'application/xml';
    }

    public function write(User $user, $out): void
    {
        $entries = $this->entries($user);
        $calendar = UserCalendar::of($user);
        $xml = new XMLWriter;
        $xml->openMemory();
        $xml->setIndent(true);
        $xml->setIndentString("\t");
        $xml->startDocument('1.0', 'UTF-8');
        $xml->startElement(MalXmlSource::ROOT);
        $this->element($xml, self::HEADER, $this->myInfo($user, $entries));
        fwrite($out, $xml->outputMemory());

        foreach ($entries as $entry) {
            $this->element($xml, $this->entryElement(), $this->fields(
                $entry,
                $calendar->day($entry->startedAt) ?? self::NO_DAY,
                $calendar->day($entry->finishedAt) ?? self::NO_DAY,
            ));
            fwrite($out, $xml->outputMemory());
        }

        $xml->endElement();
        $xml->endDocument();
        fwrite($out, $xml->outputMemory());
    }

    /**
     * The header MyAnimeList opens a list with: whose it is (no MyAnimeList
     * user id: the file is not theirs) and how many entries stand in each
     * status.
     *
     * @param  list<AnimeListEntry>  $entries
     * @return array<string, string|int>
     */
    private function myInfo(User $user, array $entries): array
    {
        $counts = array_fill_keys(array_column(ListStatus::cases(), 'value'), 0);

        foreach ($entries as $entry) {
            $counts[$entry->status->value]++;
        }

        return [
            'user_id' => 0,
            'user_name' => $user->username,
            'user_export_type' => $this->exportType(),
            ...$this->totals(count($entries), $counts),
        ];
    }

    /**
     * Text is escaped, not wrapped in CDATA as MyAnimeList wraps it: the same
     * text to any XML reader, and no title can close the section early.
     *
     * @param  array<string, string|int>  $fields
     */
    private function element(XMLWriter $xml, string $name, array $fields): void
    {
        $xml->startElement($name);

        foreach ($fields as $field => $value) {
            $xml->writeElement($field, (string) $value);
        }

        $xml->endElement();
    }
}

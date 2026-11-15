<?php

namespace App\Integrations\Export;

use App\Models\User;

/**
 * A download of the user's library in one shape. A new format is a class in
 * its integration and one registration in {@see ExportFormatRegistry}; the
 * export page and its route take every registered format as it comes.
 */
interface ExportFormat
{
    /** The format's slug in the download address. */
    public function code(): string;

    /** What its button on the export page says. */
    public function label(): string;

    public function shelf(): ExportShelf;

    /** What the file name says after the user's name; null for the whole backup. */
    public function part(): ?string;

    public function extension(): string;

    public function contentType(): string;

    /**
     * Writes the user's library to the stream, entry by entry.
     *
     * @param  resource  $out
     */
    public function write(User $user, $out): void;
}

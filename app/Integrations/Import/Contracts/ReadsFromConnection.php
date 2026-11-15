<?php

namespace App\Integrations\Import\Contracts;

/**
 * An import source whose input is a connection rather than a file or a
 * form. It names the connectable service it reads through; the import page
 * shows that service's connect card, and a run gets the connection's id.
 */
interface ReadsFromConnection extends ImportSource
{
    public function connectionService(): string;

    /**
     * How often auto-import runs this source, in minutes — a pace the
     * service can bear (Simkl asks for daily-ish, Tracearr is the user's
     * own server).
     */
    public function autoImportMinutes(): int;
}

<?php

namespace App\Integrations\Import\Contracts;

use App\Integrations\Import\Data\ImportInput;
use App\Integrations\Import\Data\ImportManifest;
use App\Integrations\Import\Data\ImportSelection;
use App\Integrations\Import\Rows\ImportRow;
use Generator;

/**
 * Everything an import source has to know how to do. Adding Letterboxd or
 * Goodreads is writing one of these and registering it — nothing in the job,
 * the controller or the import page changes: they render and run whatever
 * the registry holds.
 *
 * What the user hands over is told by which of ReadsFile, ReadsCredentials
 * or ReadsFromConnection the source also implements.
 *
 * A source only reads and translates. It never writes: rows go to the
 * ImportRunner, which writes them through the library's Backfill actions, so
 * "additive, idempotent, never overwrite" holds in one place for every source.
 */
interface ImportSource
{
    /** Stable code; it names the task run (import-{code}) and the MutationSource. */
    public function code(): string;

    /** The radio label on the import page, as an i18n key. */
    public function label(): string;

    /** One line under the label, as an i18n key. */
    public function description(): string;

    /** How the run reads on the Tasks page, as an i18n key. */
    public function taskLabel(): string;

    /**
     * A look at the input before anything is written: is it ours, what does
     * it hold. An input that is not ours comes back invalid with the reason.
     */
    public function analyze(ImportInput $input): ImportManifest;

    /**
     * The input translated into rows, lazily — an export can run to tens of
     * MB and is never held in full when the format allows streaming.
     *
     * A source reading through a connection may return its read position
     * (a delta timestamp, a cursor) as the generator's return value; the run
     * stores it on the connection and the next run starts from there.
     *
     * @return Generator<int, ImportRow>
     */
    public function rows(ImportInput $input, ImportSelection $selection): Generator;
}

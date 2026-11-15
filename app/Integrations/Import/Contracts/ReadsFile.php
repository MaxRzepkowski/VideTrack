<?php

namespace App\Integrations\Import\Contracts;

/** An import source whose input is an export file the user uploads. */
interface ReadsFile extends ImportSource
{
    /** The <input type=file> accept filter, e.g. '.zip'. */
    public function accept(): string;
}

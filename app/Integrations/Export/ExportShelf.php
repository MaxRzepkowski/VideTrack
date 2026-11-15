<?php

namespace App\Integrations\Export;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Where the export page offers a download: the whole backup, a flat sheet
 * of one part of the library, or another service's own import format.
 */
#[TypeScript]
enum ExportShelf: string
{
    case Backup = 'backup';
    case Spreadsheet = 'spreadsheet';
    case Service = 'service';
}

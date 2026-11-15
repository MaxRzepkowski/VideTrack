<?php

namespace App\Integrations\Export;

use App\Support\ServiceRegistry;

/**
 * The downloads this instance offers on the export page, in the order the
 * page shows them.
 *
 * @extends ServiceRegistry<ExportFormat>
 */
final class ExportFormatRegistry extends ServiceRegistry
{
    protected function kind(): string
    {
        return 'export format';
    }
}

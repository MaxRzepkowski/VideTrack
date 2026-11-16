<?php

namespace App\Integrations\Export;

/**
 * A download built with another service's data: when that data cannot be
 * had, the page answers that it cannot be built now rather than serving a
 * file that silently leaves out what it could not place.
 */
interface DependsOnService extends ExportFormat
{
    public function isAvailable(): bool;
}

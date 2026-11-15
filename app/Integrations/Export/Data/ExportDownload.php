<?php

namespace App\Integrations\Export\Data;

use App\Integrations\Export\ExportFormat;
use App\Integrations\Export\ExportShelf;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One download the export page offers.
 */
#[TypeScript]
final readonly class ExportDownload
{
    public function __construct(
        public string $code,
        /** What the button says; another service is named as the product is. */
        public string $label,
        public ExportShelf $shelf,
    ) {}

    public static function of(ExportFormat $format): self
    {
        return new self($format->code(), $format->label(), $format->shelf());
    }
}

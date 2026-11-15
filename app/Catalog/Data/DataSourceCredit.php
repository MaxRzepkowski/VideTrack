<?php

namespace App\Catalog\Data;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * How the About page credits one source of this instance's data — named by
 * the module that uses it, so a new provider brings its own credit. A notice
 * may name its licence with `:license`; only that name carries the link.
 */
#[TypeScript]
final readonly class DataSourceCredit
{
    /**
     * @param  list<string>  $notices  translation keys, or their text once {@see translated()}
     */
    public function __construct(
        public string $name,
        public string $url,
        public array $notices,
        public ?string $logo = null,
        public ?int $logoWidth = null,
        public ?int $logoHeight = null,
        public ?string $licenseName = null,
        public ?string $licenseUrl = null,
    ) {}

    /** The same credit, its notices in the reader's language. */
    public function translated(): self
    {
        return new self(
            $this->name,
            $this->url,
            array_map(fn (string $key): string => __($key), $this->notices),
            $this->logo,
            $this->logoWidth,
            $this->logoHeight,
            $this->licenseName,
            $this->licenseUrl,
        );
    }
}

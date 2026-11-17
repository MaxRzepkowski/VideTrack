<?php

namespace App\Notifications\Channels\Data;

use App\Support\Forms\CredentialField;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** A kind of channel the instance offers, and what the form asks for it. */
#[TypeScript]
final readonly class ChannelKindData
{
    /**
     * @param  list<CredentialField>  $fields
     */
    public function __construct(
        public string $driver,
        public string $code,
        public string $label,
        public array $fields,
        public ?string $setupUrl,
        public bool $popular,
    ) {}

    public static function of(string $driver, ChannelKind $kind): self
    {
        return new self($driver, $kind->code, $kind->label, $kind->fields, $kind->setupUrl, $kind->popular);
    }
}

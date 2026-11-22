<?php

namespace App\Notifications\Data;

use App\Notifications\Copy\CopyFormat;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * A value an event moved, worded: what it was (null when it had none, as a
 * date first announced) and what it is now. Kept apart rather than as one
 * sentence: a screen strikes the old one, text joins the two with an arrow
 * ({@see CopyFormat::change()}).
 */
#[TypeScript]
final readonly class ValueChange
{
    public function __construct(
        public ?string $from,
        public string $to,
    ) {}
}

<?php

namespace App\Enums;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Lifecycle of a background task run:
 * pending → started → success / failure. "Finished with errors" is derived
 * in the UI from success + a non-empty error list.
 */
#[TypeScript]
enum TaskRunStatus: string
{
    case Pending = 'pending';
    case Started = 'started';
    case Success = 'success';
    case Failure = 'failure';

    public function isFinished(): bool
    {
        return $this === self::Success || $this === self::Failure;
    }
}

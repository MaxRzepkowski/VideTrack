<?php

namespace App\Integrations\Scrobble;

/**
 * What a source says happened. `Complete`, `Stop`, `Pause`, `Progress` and
 * `Rate` mean something to the framework; `Start` and `Added` travel through
 * it so a consumer can act on them without changing this contract.
 */
enum ScrobbleEventKind: string
{
    case Start = 'start';

    case Pause = 'pause';

    case Stop = 'stop';

    case Complete = 'complete';

    /** Still going, and here is how far: never finishes by a percentage. */
    case Progress = 'progress';

    case Rate = 'rate';

    case Added = 'added';
}

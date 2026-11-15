<?php

namespace App\Accounts\Preferences;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Which departments of a followed person feed auto-follows.
 */
#[TypeScript]
enum PersonFollowScope: string
{
    /**
     * Their main field alone — an actor's acting, a director's directing — so
     * a prolific multi-hat person is a signal, not spam.
     */
    case KnownFor = 'known_for';

    /** Every core department. */
    case All = 'all';
}

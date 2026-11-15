<?php

namespace App\Accounts\Preferences;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Which image an episode shows on a release surface: the show's backdrop or
 * the episode's own still.
 */
#[TypeScript]
enum EpisodeImage: string
{
    case Backdrop = 'backdrop';
    case Still = 'still';
}
